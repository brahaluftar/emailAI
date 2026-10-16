# emailAI

PHP service that checks your mailboxes every 20 minutes, asks an OpenAI model to draft a reply
(saved to the account's Drafts folder via IMAP, or `data/drafts/` for POP3), and stores any
meetings/deadlines it finds as tentative `[TBD]` events in **one unified ICS calendar** shared by all accounts.
Nothing is ever sent automatically.

## Setup (Ubuntu / cloudclusters.io)
```bash
git clone https://github.com/brahaluftar/emailAI.git && cd emailAI
./setup.sh
```
The script installs PHP if needed, asks for the OpenAI key and each account (web login URL, e-mail,
password, IMAP/POP3 host/port/encryption), writes `.env` (chmod 600) and installs the cron job.
See `.env.example` for all variables. Run manually: `php bin/run.php`. Logs: `data/emailai.log`.

## Reuse
- Behaviour is controlled by `AGENT_INSTRUCTIONS`; `OPENAI_BASE_URL` allows any OpenAI-compatible API.
- `MailClient` interface (`src/MailClient.php`) lets you add other mail backends; `Calendar` is standalone.
- Serve/subscribe `data/calendar.ics` (e.g. via a protected URL) from any calendar app.

Tests: `php tests/run.php`
