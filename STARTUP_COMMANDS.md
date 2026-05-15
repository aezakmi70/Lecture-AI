# Startup Commands — AI Lecture Note Chatbot

Quick reference for starting the app (development) on a fresh Windows or  laptop.

---

## Backend (Windows PowerShell)

```powershell
cd "AI Lecture Note Chatbot/api-backend"
composer install
cp .env.example .env    # or: copy .env.example .env
# Edit .env: set DB_*, OPENROUTER_API_KEY or OPENAI_API_KEY, APP_URL
php artisan key:generate
php artisan migrate
php artisan storage:link
php artisan serve --host=127.0.0.1 --port=8000
```


## Frontend (Windows / macOS / Linux)

```bash
cd "AI Lecture Note Chatbot/frontend"
npm install
npm run dev          # starts Vite dev server
# or for production build:
npm run build
```

## Python PDF fallback (optional)

```bash
cd "AI Lecture Note Chatbot/api-backend/scripts"
python -m venv venv
# Windows
venv\Scripts\activate
# macOS/Linux
source venv/bin/activate
pip install PyPDF2
```

## Test summarize endpoint (PowerShell example)

```powershell
$body = @{ document_id = 4 } | ConvertTo-Json
Invoke-RestMethod -Method Post -Uri 'http://127.0.0.1:8000/api/ai/summarize' -ContentType 'application/json' -Body $body
```

## Notes & troubleshooting
- Ensure PHP version is ^8.2 and Composer is installed and on PATH.
- If using XAMPP, make sure MySQL is running and `.env` DB credentials match.
- Set `OPENROUTER_API_KEY` (preferred) or `OPENAI_API_KEY` in `.env` and restart `php artisan serve` after changing `.env`.
- If summaries truncate, check API quotas and increase token budgets in `api-backend/app/Http/Controllers/AiController.php` if needed.

---

If you want, I can also add a `start-dev.ps1` (PowerShell) or `start-dev.sh` script to automate these steps. Tell me which you prefer.
