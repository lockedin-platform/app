# Tests d'Acceptation (Story Tests) - Sprint 2

## Module 1 : Investissement

### US-INV-01 : Consultation des opportunités d'investissement
```gherkin
Given   un investisseur authentifié
When    il accède à la page "/investissement/opportunities"
Then    il voit la liste des opportunités d'investissement ouvertes
And     chaque opportunité affiche le titre, secteur, montant cible et statut
```

### US-INV-02 : Soumission d'une offre d'investissement
```gherkin
Given   un investisseur authentifié sur la page d'une opportunité
When    il soumet une offre avec montant = 50000 EUR et message = "Intéressé"
Then    l'offre est créée avec statut "PENDING"
And     l'entrepreneur reçoit une notification dans son inbox
And     l'offre apparaît dans "/investissement/my-offers"
```

### US-INV-03 : Négociation des termes du contrat
```gherkin
Given   une offre acceptée entre entrepreneur et investisseur
When    l'entrepreneur modifie les termes (montant, échéances, conditions)
Then    le contrat est mis à jour avec les nouveaux termes
And     l'investisseur peut consulter les modifications dans "/contract"
And     un historique des messages est disponible via "/messages"
```

### US-INV-04 : Signature SHA-256 du contrat
```gherkin
Given   un contrat avec termes acceptés par les deux parties
When    l'investisseur signe le contrat via POST "/sign"
Then    une signature SHA-256 est générée et stockée
And     le contrat passe au statut "SIGNED"
And     un QR code de vérification est généré via "/qr"
And     la vérification est accessible publiquement via "/verifier"
```

### US-INV-05 : Jalons de paiement conditionnel
```gherkin
Given   un contrat signé avec des jalons définis
When    l'entrepreneur marque un jalon comme "complete"
Then    l'investisseur reçoit une notification de validation
When    l'investisseur confirme le jalon via "/confirm"
Then    le paiement partiel est libéré via "/release"
And     le statut du jalon passe à "RELEASED"
```

### US-INV-06 : Analyse de risque économique
```gherkin
Given   un investisseur sur le dashboard avancé
When    il lance l'analyse de risque pour une opportunité
Then    le système calcule un score de risque (0-100)
And     affiche les facteurs : taux de change, PIB, inflation
And     génère un verdict déterministe (Faible/Modéré/Élevé)
```

---

## Module 2 : Utilisateurs

### US-USR-01 : Inscription avec vérification email
```gherkin
Given   un visiteur non inscrit
When    il remplit le formulaire d'inscription (nom, email, mot de passe)
And     passe la vérification reCAPTCHA
Then    un compte est créé avec statut "non vérifié"
And     un email de vérification est envoyé
When    il clique sur le lien de vérification
Then    son compte passe en statut "vérifié"
```

### US-USR-02 : Connexion avec Google OAuth
```gherkin
Given   un utilisateur avec un compte Google
When    il clique sur "Se connecter avec Google"
Then    il est redirigé vers Google OAuth
When    il autorise l'application
Then    il est connecté et redirigé vers son profil
And     si c'est sa première connexion, un compte est créé automatiquement
```

### US-USR-03 : Connexion par reconnaissance faciale
```gherkin
Given   un utilisateur inscrit ayant enregistré son visage
When    il accède à "/face-login" et active sa caméra
Then    le système capture son visage et compare avec l'enregistrement
When    la correspondance est confirmée
Then    il est authentifié et redirigé vers son dashboard
```

### US-USR-04 : Génération de bio par IA
```gherkin
Given   un utilisateur authentifié sur sa page de profil
When    il clique sur "Générer ma bio avec l'IA"
Then    le service GeminiService génère une bio personnalisée
And     la bio est affichée en prévisualisation
When    l'utilisateur valide
Then    la bio est sauvegardée dans son profil
```

### US-USR-05 : Réinitialisation du mot de passe
```gherkin
Given   un utilisateur ayant oublié son mot de passe
When    il saisit son email sur "/forgot-password"
Then    un code de réinitialisation est envoyé par email
When    il saisit le code correct sur "/reset-code"
Then    il accède au formulaire de nouveau mot de passe
When    il définit un nouveau mot de passe valide
Then    son mot de passe est mis à jour et il peut se connecter
```

---

## Module 3 : Mentorat

### US-MEN-01 : Recherche de mentors avec matching IA
```gherkin
Given   un entrepreneur authentifié
When    il accède à la liste des mentors "/mentorat/mentors"
Then    les mentors sont affichés avec un score de compatibilité
And     le score est calculé par MentoratMatchingService (bio, secteur, expérience)
And     les mentors sont triés par pertinence
```

### US-MEN-02 : Demande de mentorat
```gherkin
Given   un entrepreneur ayant identifié un mentor compatible
When    il soumet une demande avec sujet et message
Then    la demande est créée avec statut "EN_ATTENTE"
And     le mentor reçoit une notification
When    le mentor accepte la demande via "/respond"
Then    le statut passe à "ACCEPTEE"
And     les deux parties peuvent planifier des sessions
```

### US-MEN-03 : Planification et gestion des sessions
```gherkin
Given   une demande de mentorat acceptée
When    le mentor crée une nouvelle session (date, durée, sujet)
Then    la session apparaît dans le calendrier des deux parties
And     un rappel est envoyé avant la session
When    la session est terminée
Then    l'entrepreneur peut laisser un feedback (note + commentaire)
```

### US-MEN-04 : Gestion des disponibilités
```gherkin
Given   un mentor authentifié
When    il définit ses créneaux de disponibilité
Then    les créneaux sont visibles par les entrepreneurs
And     les demandes ne peuvent être faites que sur créneaux disponibles
When    il modifie ou supprime un créneau
Then    le calendrier est mis à jour en temps réel
```

### US-MEN-05 : Export des sessions (PDF/Excel)
```gherkin
Given   un utilisateur avec un historique de sessions
When    il clique sur "Exporter en PDF" ou "Exporter en Excel"
Then    un document est généré avec la liste des sessions
And     chaque session inclut : date, mentor/mentee, sujet, feedback
And     le fichier est téléchargé automatiquement
```

---

## Module 4 : Communauté

### US-COM-01 : Publication de posts avec modération IA
```gherkin
Given   un utilisateur authentifié dans la section communauté
When    il publie un nouveau post (texte + image optionnelle)
Then    le contenu est analysé par CommunityTextModerationService
And     si le contenu est approprié, le post est publié
And     si du contenu inapproprié est détecté, le post est bloqué avec un message
```

### US-COM-02 : Gestion des groupes communautaires
```gherkin
Given   un utilisateur authentifié
When    il crée un nouveau groupe (nom, description, visibilité)
Then    le groupe est créé et il en devient l'administrateur
When    un autre utilisateur demande à rejoindre
Then    l'admin reçoit la demande et peut approuver/rejeter
When    approuvé, le membre accède aux threads et événements du groupe
```

### US-COM-03 : Événements avec météo et tickets
```gherkin
Given   un administrateur de groupe
When    il crée un événement (date, lieu, description)
Then    CommunityWeatherService fournit la prévision météo pour la date
And     des tickets sont générés pour les participants
When    un participant scanne son ticket à l'entrée
Then    CommunityTicketService valide le ticket
And     le participant est marqué comme présent
```

### US-COM-04 : Traduction automatique des posts
```gherkin
Given   un utilisateur consultant un post en langue étrangère
When    il clique sur "Traduire"
Then    CommunityPostTranslationService traduit le contenu
And     la traduction est affichée sous le post original
And     la langue source est détectée automatiquement
```

### US-COM-05 : Résumé IA et suggestions de réponses
```gherkin
Given   un thread avec de nombreux commentaires
When    l'utilisateur clique sur "Résumer le thread"
Then    CommunityAiService génère un résumé du thread
When    il clique sur "Suggestions de réponse"
Then    3 suggestions de réponses contextuelles sont proposées
```

---

## Module 5 : Projets

### US-PRJ-01 : Création et évaluation d'un projet
```gherkin
Given   un entrepreneur authentifié
When    il crée un nouveau projet (titre, description, secteur, étape)
And     renseigne les données business (marché, revenus, coûts, risque, équipe)
Then    le projet est sauvegardé avec statut "BROUILLON"
When    il lance l'évaluation IA via "/evaluate-ai"
Then    ProjetScoringService calcule les scores (financier, marché, équipe, risque)
And     un score global /100 est affiché
And     un diagnostic IA est généré
```

### US-PRJ-02 : Génération du Business Plan
```gherkin
Given   un projet avec données business complètes
When    l'entrepreneur accède à "/business-plan"
Then    un business plan structuré est généré (résumé, marché, finances, stratégie)
When    il clique sur "Télécharger en PDF"
Then    ProjetExportService génère un PDF professionnel
And     le fichier est téléchargé
```

### US-PRJ-03 : Recommandations personnalisées
```gherkin
Given   un projet évalué par le scoring
When    l'entrepreneur consulte les recommandations
Then    ProjetRecommendationService analyse le profil du projet
And     propose des actions prioritaires pour améliorer le score
And     suggère des ressources et formations pertinentes
```

### US-PRJ-04 : Dashboard et statistiques
```gherkin
Given   un entrepreneur avec plusieurs projets
When    il accède au dashboard "/projets/dashboard"
Then    il voit une vue synthétique de tous ses projets
And     les scores globaux, secteurs et étapes sont visualisés
And     une comparaison avec la moyenne est affichée
```

### US-PRJ-05 : Chatbot projet
```gherkin
Given   un entrepreneur authentifié
When    il accède au chatbot "/projets/chatbot"
And     pose une question sur son projet
Then    le chatbot répond avec des conseils contextualisés
And     utilise les données du projet pour personnaliser la réponse
```

---

## Module 6 : Apprentissage

### US-APP-01 : Catalogue et inscription aux cours
```gherkin
Given   un utilisateur authentifié
When    il consulte le catalogue "/apprentissage/cours"
Then    les cours sont listés avec titre, catégorie, niveau de difficulté
When    il clique sur "S'inscrire" pour un cours
Then    une progression est créée avec état "NON_COMMENCE"
And     le cours apparaît dans "/apprentissage/progression"
```

### US-APP-02 : Suivi de progression
```gherkin
Given   un utilisateur inscrit à un cours
When    il consulte le cours et avance dans le contenu
And     met à jour sa progression via POST "/progress"
Then    le pourcentage de complétion est mis à jour
And     l'état passe de "NON_COMMENCE" → "EN_COURS" → "COMPLETE"
When    il atteint 100%
Then    un badge est potentiellement débloqué
```

### US-APP-03 : Quiz généré par IA
```gherkin
Given   un utilisateur inscrit à un cours
When    il accède au quiz "/apprentissage/cours/{id}/quiz"
Then    CoursQuizService génère un quiz basé sur le contenu du cours
And     3 questions à choix multiples sont présentées
And     chaque question a 4 options et une bonne réponse
When    l'utilisateur répond correctement
Then    sa progression est bonifiée
```

### US-APP-04 : Système de badges et gamification
```gherkin
Given   un utilisateur avec des cours complétés
When    il consulte "/apprentissage/badges"
Then    ses badges obtenus sont affichés (COMMUN, RARE, EPIQUE, LEGENDAIRE)
And     les badges non obtenus montrent les conditions de déblocage
And     un compteur de progression vers le prochain badge est visible
```

### US-APP-05 : Export PDF de la progression
```gherkin
Given   un utilisateur avec un historique d'apprentissage
When    il clique sur "Exporter ma progression en PDF"
Then    un PDF est généré avec la liste des cours et pourcentages
And     les badges obtenus sont inclus
And     le document est téléchargé automatiquement
```

---

## Résumé

| Module | User Stories | Scénarios Given/When/Then |
|--------|:---:|:---:|
| Investissement | 6 | 6 |
| Utilisateurs | 5 | 5 |
| Mentorat | 5 | 5 |
| Communauté | 5 | 5 |
| Projets | 5 | 5 |
| Apprentissage | 5 | 5 |
| **TOTAL** | **31** | **31** |
