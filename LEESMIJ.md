# KeyVue voor Gravity Forms — 2.0.0

Stuurt Gravity Forms-inzendingen als lead naar KeyVue.

## Installeren

1. **Plugins → Nieuwe plugin → Plugin uploaden**, kies de ZIP en activeer. Een oudere versie wordt vervangen; instellingen worden overgenomen.
2. Ga naar **Formulieren → Instellingen → KeyVue**.
3. Plak bij **Instellingen** de webhook-URL van KeyVue voor deze website en sla op.

Daarna werkt het: **elk formulier met een e-mailveld stuurt zijn inzendingen automatisch door**. Tot de webhook-URL is ingevuld wordt er niets verstuurd.

## Nieuwe formulieren

Een nieuw formulier hoeft niet ingesteld te worden. De plug-in herkent de velden zelf:

- het naam-, e-mail-, telefoon- en adresveld van Gravity Forms;
- gewone tekstvelden op basis van hun label, bijvoorbeeld Voornaam, Achternaam, Naam, Telefoon, Postcode, Huisnummer, Straat, Woonplaats, Land, Geslacht, Geboortedatum (Nederlands en Engels);
- velden met een parameternaam gelijk aan de KeyVue-naam (bijvoorbeeld `zip` of `first_name`).

Alleen het e-mailadres is verplicht. Wat niet in een formulier zit, gaat niet mee. Een formulier zonder e-mailveld wordt overgeslagen.

Handig om te weten:

- Eén veld "Naam" wordt gesplitst: het eerste woord is de voornaam, de rest de achternaam.
- Het tussenvoegsel van het naamveld komt vóór de achternaam.
- Straat en huisnummer in één veld ("Dorpsstraat 10a") worden gesplitst in straatnaam en huisnummer.
- De postcode gaat zonder spaties en in hoofdletters mee.
- Berichttekst en andere velden gaan niet naar KeyVue; daar heeft KeyVue geen veld voor opgegeven.

Zet een formulier dat geen leads oplevert (nieuwsbrief, sollicitatie) uit met **Uitzetten** in het overzicht.

## Controleren en aanpassen

Het overzicht toont per formulier de status en welke gegevens meegaan. Een ⚠ betekent dat meerdere velden passen (bijvoorbeeld twee telefoonvelden); de eerste is gekozen.

Via **Velden en test** kun je per formulier:

- zelf een veld kiezen als de automatische keuze niet klopt, of een gegeven niet meesturen;
- een **test uitvoeren**: je ziet precies wat KeyVue zou ontvangen voor de laatste (of een gekozen) inzending. Er wordt daarbij niets verstuurd. Zonder inzendingen toont de test voorbeeldgegevens.

## Verzending en fouten

- Een inzending wordt direct na het versturen van het formulier doorgestuurd; de bezoeker wacht daar niet op. Op hosting zonder PHP-FPM of LiteSpeed loopt dit via WordPress-cron (binnen ongeveer een minuut).
- Bij elke inzending staat een **KeyVue-blok** met de status. Daar kun je ook opnieuw versturen, of een oude inzending alsnog versturen.
- Gaat het mis, dan krijgt het ingestelde adres (standaard robert@dijs.media) een e-mail, hooguit één per tien minuten. De e-mail bevat geen gegevens van de aanvrager.
- **Mislukt**: KeyVue weigerde de aanvraag of het e-mailadres is ongeldig.
- **Controleren**: time-out of serverfout bij KeyVue. De lead kan al zijn aangekomen; kijk eerst in KeyVue voordat je opnieuw verstuurt, anders kan er een dubbele lead ontstaan.
- HTTP 429 (te druk) wordt automatisch opnieuw geprobeerd, maximaal zes keer.
- Met **Afgehandeld** verdwijnt een probleem uit de waarschuwingen.
- Spam en verwijderde inzendingen worden overgeslagen. Elke inzending wordt hooguit één keer automatisch verstuurd.

"Verstuurd" betekent dat KeyVue de aanvraag heeft geaccepteerd (HTTP 2xx). Controleer bij de eerste echte test in KeyVue of contact, pijplijn, team en adres kloppen.

## Privacy en beheer

- De webhook-URL staat alleen in de database en wordt niet teruggetoond. Alleen `https://api.keyvue.nl/api/v1/service/active_campaign/referral` wordt geaccepteerd; redirects worden niet gevolgd.
- De wachtrij bevat geen contactgegevens; die worden bij verzending uit de inzending gelezen.
- Geslaagde verzendingen verdwijnen na 90 dagen uit het overzicht; open problemen blijven staan.
- Instellingen wijzigen kan alleen een beheerder.
- Verwijderen van de plug-in laat instellingen en wachtrij staan. Definitief opruimen: tabel `{prefix}keyvue_gf_jobs` en de opties `keyvue_gf_global`, `keyvue_gf_settings`, `keyvue_gf_version`, `keyvue_gf_queue_error`.

## Getest

Getest in een lokale WordPress 6.8 met Gravity Forms: veldherkenning, verzending, foutafhandeling (500, 429, ongeldig/ontbrekend e-mailadres), uitgezette formulieren, dubbele hooks, alle knoppen in het beheer en de e-mailmelding. De verzoeken naar KeyVue waren daarbij onderschept en de database was SQLite. Er is niets naar de echte webhook gestuurd; de eerste echte lead moet op staging gecontroleerd worden.
