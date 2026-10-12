# StreamLive AI local setup

The current development override is `inc/config.local.php`. It selects a separate SQLite database at `storage/streamlive-local.sqlite`; production defaults remain in `inc/config.php` and still use MariaDB.

Start the local app with PHP's built-in server from the project root:

```powershell
C:\xamppnew\php\php.exe -d session.save_path=storage -S 127.0.0.1:8087 -t . dev-router.php
```

Open `http://127.0.0.1:8087/register` to create the owner's account. Then, from a local terminal, assign system ownership to that exact account:

```powershell
C:\xamppnew\php\php.exe tools\promote-super-admin.php owner@example.com
```

The command only works from CLI, requires an existing account, and refuses to replace an already configured super admin. The System Admin link and operations dashboard require that role.

Sign in as super admin and open `http://127.0.0.1:8087/admin`. Enter the credentials under **Provider connections** and choose **Save provider keys**. Saved values stay masked; blank fields keep the current value, and **Clear saved key** removes it. The form accepts credentials only over HTTPS or from loopback. Values are stored in `storage/provider-secrets.json`, which Apache and the local development router deny over HTTP.

The providers needed for the current integrations are:

- Decart: `decart.api_key` for Livestream and image editing.
- ElevenLabs: `elevenlabs.api_key` for voice features.
- fal.ai: `fal.api_key` for image-to-video generation.
- Paystack: `paystack.secret_key` for credit purchases.

The admin page reports whether each key exists without showing its value. Mail uses the local log driver; configure SMTP separately when you want to test actual email delivery. These API services need valid credentials and provider-side account access before their paid features can be exercised.
