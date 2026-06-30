# Xero Receipt App
**Automated receipt emails for Globe Success Learning**

When a Xero invoice is marked **PAID**, this app automatically:
1. Receives a Xero webhook
2. Fetches the invoice details
3. Generates a PDF receipt
4. Emails the receipt to the customer

---

## Project Structure

```
xero-receipt-app/
├── public/
│   ├── index.php        # Dashboard — connect Xero, view sent receipts
│   ├── callback.php     # OAuth2 callback from Xero
│   ├── webhook.php      # Xero webhook endpoint (invoices)
│   ├── download.php     # Serve PDF receipts for download
│   └── disconnect.php   # Revoke Xero connection
├── src/
│   ├── Config/Bootstrap.php        # .env loader, required vars check
│   ├── Xero/
│   │   ├── OAuthClient.php         # OAuth2: auth URL, token exchange, refresh
│   │   ├── TokenStorage.php        # Save/load tokens from DB
│   │   ├── WebhookVerifier.php     # HMAC-SHA256 signature check
│   │   └── XeroApiClient.php       # Fetch invoice data from Xero API
│   ├── Services/
│   │   ├── ReceiptService.php      # Orchestrates the full receipt pipeline
│   │   └── DuplicateGuard.php      # Prevents double-sending
│   ├── PDF/ReceiptGenerator.php    # Dompdf: HTML → PDF
│   ├── Email/EmailSender.php       # PHPMailer: send receipt email
│   ├── Storage/Database.php        # PDO/SQLite wrapper
│   └── Helpers/Logger.php          # Log to file + DB
├── templates/
│   ├── pdf/receipt.html            # Receipt PDF template (easy to customise)
│   └── email/receipt.html          # Receipt email template (easy to customise)
├── storage/
│   ├── logs/                       # Daily log files
│   └── receipts/                   # Generated PDF receipts
├── database/
│   ├── schema.sql                  # Table definitions
│   └── app.sqlite                  # Auto-created on first run
├── .env.example
└── composer.json
```

---

## Local Setup (Step by Step)

### 1. Install PHP 8+ and Composer
Make sure `php` and `composer` are on your PATH.

### 2. Clone/download the project
```bash
cd /your/projects/folder
```

### 3. Install dependencies
```bash
composer install
```

### 4. Copy and fill in .env
```bash
cp .env.example .env
```

Edit `.env` with your:
- Xero Client ID + Secret (from https://developer.xero.com/myapps)
- SMTP credentials (Gmail app password works great)
- Business details for the receipt

### 5. Create storage directories (if not present)
```bash
mkdir -p storage/logs storage/receipts database
chmod -R 775 storage database
```

### 6. Start the local dev server
```bash
composer start
# Server running at http://localhost:8080
```

### 7. Open the dashboard
Visit: http://localhost:8080

---

## Setting Up ngrok (for local Xero webhook testing)

Xero needs a public HTTPS URL to send webhooks. ngrok tunnels your localhost.

### Install ngrok
```bash
# Mac
brew install ngrok

# Or download from https://ngrok.com/download
```

### Start a tunnel
```bash
ngrok http 8080
```

You'll see output like:
```
Forwarding  https://abc123.ngrok-free.app -> http://localhost:8080
```

### Update your .env
```
APP_URL=https://abc123.ngrok-free.app
XERO_REDIRECT_URI=https://abc123.ngrok-free.app/callback.php
```

**Important:** Every time you restart ngrok, the URL changes. Update `.env` + Xero app settings each time.

---

## Xero Developer App Setup

1. Go to https://developer.xero.com/myapps
2. Create a new app (or use existing)
3. Set **Redirect URI** to: `https://your-ngrok-url.ngrok-free.app/callback.php`
4. Copy **Client ID** and **Client Secret** to your `.env`

### Webhook Setup
1. In your Xero app settings, go to **Webhooks**
2. Set the delivery URL to: `https://your-ngrok-url.ngrok-free.app/webhook.php`
3. Subscribe to: **Invoices**
4. Copy the **Webhook signing key** to `XERO_WEBHOOK_KEY` in `.env`
5. Click **Send intent to receive** — your app must respond 200 to activate

---

## Testing the Full Flow

### Option A: Using Xero Demo Company
1. Connect to the **Demo Company** (it's a fake test org inside Xero)
2. Open an invoice → change status to **Awaiting Payment** → add a payment
3. Xero fires the webhook → your app sends a receipt

### Option B: Trigger manually (dev testing)
Add this to your `.env`:
```
APP_ENV=local
```

Then call the receipt service directly in a test script:
```php
require 'vendor/autoload.php';
App\Config\Bootstrap::init();
App\Storage\Database::migrate();

$service = new App\Services\ReceiptService();
$service->processInvoice('your-xero-invoice-guid-here');
```

---

## Customising the Receipt

### PDF Template
Edit: `templates/pdf/receipt.html`
- All `{{VARIABLE}}` placeholders are replaced at render time
- Change fonts, colours, add logo — it's just HTML/CSS

### Email Template
Edit: `templates/email/receipt.html`
- Same placeholder system
- Test by sending to yourself first

### Business Details
All in `.env`:
```
BUSINESS_NAME=Globe Success Learning
BUSINESS_ADDRESS=123 Main St, City, State
BUSINESS_EMAIL=info@globesuccesslearning.com
BUSINESS_PHONE=+1 000 000 0000
BUSINESS_WEBSITE=https://globesuccesslearning.com
```

---

## Webhook Flow (What Happens)

```
Invoice updated in Xero
    │
    ▼
POST /webhook.php
    │
    ├─ Verify HMAC-SHA256 signature  ──(fail)──► HTTP 401, stop
    │
    ├─ Check events array
    │
    ▼
For each INVOICE event:
    │
    ├─ DuplicateGuard: already sent? ──(yes)──► skip
    │
    ├─ Fetch invoice from Xero API
    │
    ├─ Is status == PAID? ─────────────(no)──► skip
    │
    ├─ Extract: contact, email, amount, line items
    │
    ├─ Generate PDF receipt (Dompdf)
    │
    ├─ Send email (PHPMailer) with PDF attached + download link
    │
    └─ Mark as sent in sent_receipts table
```

---

## Common Issues & Fixes

| Problem | Fix |
|---|---|
| Webhook not received | Check ngrok is running, APP_URL in .env matches |
| Signature mismatch (401) | Verify XERO_WEBHOOK_KEY matches exactly from Xero dashboard |
| Token expired | Click "Connect Xero" again — it refreshes automatically |
| Email not sending | Use Gmail App Password (not regular password), enable 2FA |
| PDF blank | Check BUSINESS_NAME and other vars are set in .env |
| SQLite permission error | `chmod 775 database/` |
| Can't see logs | Check `storage/logs/app-YYYY-MM-DD.log` |

---

## Production Deployment (Apache/Nginx)

### Apache — set document root to `/public`
```apache
<VirtualHost *:443>
    DocumentRoot /var/www/xero-receipt-app/public
    ServerName yourdomain.com
    <Directory /var/www/xero-receipt-app/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### Nginx
```nginx
server {
    root /var/www/xero-receipt-app/public;
    index index.php;
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### Permissions
```bash
chown -R www-data:www-data storage/ database/
chmod -R 775 storage/ database/
```
