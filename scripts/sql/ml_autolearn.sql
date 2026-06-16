-- ML auto-learn foundation for LockedIn (Phase 1: "log everything from day one").
-- Safe: only creates objects in the ml_data schema + a read-only VIEW over public tables.
-- Run:  psql "$DATABASE_URL" -f scripts/sql/ml_autolearn.sql
--   (or via scripts/load_to_db helper)

CREATE SCHEMA IF NOT EXISTS ml_data;

-- 1) Generic event log the app writes to (via App\Service\MlEventLogger).
--    Captures: project submissions/evaluations, investor actions, deals, mentor sessions.
CREATE TABLE IF NOT EXISTS ml_data.platform_event (
    id          BIGSERIAL PRIMARY KEY,
    event_type  VARCHAR(60)  NOT NULL,          -- e.g. project_submitted, investor_applied, deal_closed
    entity_type VARCHAR(60),                    -- projet, investor_application, mentorship_session...
    entity_id   INTEGER,
    user_id     INTEGER,
    payload     JSONB,                          -- snapshot of the relevant fields at event time
    created_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS ix_platform_event_type    ON ml_data.platform_event (event_type);
CREATE INDEX IF NOT EXISTS ix_platform_event_entity  ON ml_data.platform_event (entity_type, entity_id);
CREATE INDEX IF NOT EXISTS ix_platform_event_created ON ml_data.platform_event (created_at);

-- 2) Live training view: turns every real submitted project into a row in the
--    scoring feature schema, so models can auto-learn from platform data as it grows.
--    label stays NULL until an outcome (funded / failed) is known.
CREATE OR REPLACE VIEW ml_data.v_project_training AS
SELECT
    p.id                                                                       AS project_id,
    p.user_id,
    p.secteur                                                                  AS sector,
    p.pays                                                                     AS country,
    p.etape                                                                    AS stage,
    d.taille_equipe                                                            AS team_size,
    d.experience_equipe                                                        AS founder_experience_years,
    d.traction                                                                 AS product_traction_users,
    NULLIF(regexp_replace(COALESCE(d.taille_marche, ''), '[^0-9.]', '', 'g'), '')::numeric AS market_size_raw,
    d.objectif_financement                                                     AS funding_target,
    d.revenus_attendus                                                         AS expected_revenue,
    d.couts_estimes                                                            AS estimated_costs,
    d.marge_estimee                                                            AS margin,
    p.score_global,
    p.statut_projet,
    p.date_creation,
    p.date_soumission,
    -- Outcome label for the auto-learn loop (the success/fail tag the models train on):
    --   'funded'  = POSITIVE: an investor offer on this project was accepted.
    --   'stalled' = NEGATIVE: project is >90 days old AND had ZERO activity in the last 90 days
    --               (no investor application/offer, no project event) — i.e. it went nowhere.
    --   NULL      = unknown / too recent to judge.
    -- Recent activity resets the 90-day clock, so an active project is never marked stalled.
    CASE
        WHEN EXISTS (
            SELECT 1 FROM ml_data.platform_event e
            WHERE e.event_type = 'offer_accepted'
              AND (e.payload->>'project_id') ~ '^[0-9]+$'
              AND (e.payload->>'project_id')::int = p.id
        ) THEN 'funded'
        WHEN p.date_creation < (CURRENT_DATE - INTERVAL '90 days')
             AND NOT EXISTS (
                 SELECT 1 FROM ml_data.platform_event e
                 WHERE e.created_at > (CURRENT_DATE - INTERVAL '90 days')
                   AND ( (e.entity_type = 'projet' AND e.entity_id = p.id)
                         OR ((e.payload->>'project_id') ~ '^[0-9]+$'
                             AND (e.payload->>'project_id')::int = p.id) )
             ) THEN 'stalled'
        ELSE NULL
    END AS outcome_label
FROM public.projet p
LEFT JOIN public.donnees_business d ON d.projet_id = p.id;
