# RepairCafe Planner

WordPress plugin voor Repair Café Renkum.

Deze plugin beheert reparatiedagen, vrijwilligers, expertises, aanmeldingen en e-maillijsten via de WordPress backend en frontend.

## Functies

- Repair Café evenementen beheren
- Vrijwilligers aanmelden en afmelden
- 24-uursregel voor afmelden
- Expertises koppelen aan vrijwilligers
- Maximaal aantal vrijwilligers per expertise
- Overzicht van aanmeldingen
- Frontend weergave voor evenementen en eigen aanmeldingen
- Backendpagina voor e-maillijsten
- E-mailadressen kopiëren
- BCC-lijst tonen
- Mailknop openen vanuit de backend

## Plugin structuur

repaircafe-planner/
- README.md
- repaircafe-planner.php
- includes/
  - admin.php
  - database.php
  - repairs.php

## Bestanden

### repaircafe-planner.php
Hoofdbestand van de plugin.

### includes/admin.php
Bevat:
- Expertises beheer
- E-maillijsten pagina
- Kopieerknoppen
- Mailknop

### includes/database.php
Bevat:
- Tabellen
- Opslag data

### includes/repairs.php
Bevat:
- Events ophalen
- Aanmelden / afmelden

## Backend menu

Repair Cafés →
- Expertises
- E-maillijsten

## E-maillijsten

Mogelijkheden:
- alle vrijwilligers
- aangemeld voor event
- expertise + niet aangemeld
- expertise + wel aangemeld

Inclusief:
- snelle lijst
- ; lijst
- BCC lijst
- kopieerknoppen
- mail knop

## Installatie

1. Upload naar wp-content/plugins/repaircafe-planner
2. Activeer plugin
3. Maak events
4. Stel expertises in

## Belangrijke regel

Afmelden binnen 24 uur is niet toegestaan.

## Doel

Vrijwilligersplanning eenvoudig maken voor Repair Café.

##Versiebeheer

Plugin wordt beheerd via GitHub en gedeployed naar de live website.
