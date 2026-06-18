-- ML auto-learn live training view for LockedIn.
-- SINGLE statement on purpose: the Render deploy recreates it via
--   doctrine:query:sql "$(cat scripts/sql/ml_autolearn.sql)"
-- which runs ONE statement. The ml_data schema already exists (created by load_to_db.py /
-- present in the migrated DB). The event log lives in public.platform_event (app-managed,
-- columns: id, event_type, user_id, user_role, entity_id, entity_type, payload json, occurred_at) —
-- it is NOT created here so doctrine:schema:update can't crash on the view dependency.
--
-- Turns every real submitted project into a scoring-feature row; outcome_label is the success/fail
-- tag the models train on (NULL until known).
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
            SELECT 1 FROM public.platform_event e
            WHERE e.event_type = 'offer_accepted'
              AND (e.payload->>'project_id') ~ '^[0-9]+$'
              AND (e.payload->>'project_id')::int = p.id
        ) THEN 'funded'
        WHEN p.date_creation < (CURRENT_DATE - INTERVAL '90 days')
             AND NOT EXISTS (
                 SELECT 1 FROM public.platform_event e
                 WHERE e.occurred_at > (CURRENT_DATE - INTERVAL '90 days')
                   AND ( (e.entity_type = 'projet' AND e.entity_id = p.id)
                         OR ((e.payload->>'project_id') ~ '^[0-9]+$'
                             AND (e.payload->>'project_id')::int = p.id) )
             ) THEN 'stalled'
        ELSE NULL
    END AS outcome_label
FROM public.projet p
LEFT JOIN public.donnees_business d ON d.projet_id = p.id;
