# Panaderia Pilipina

## Run locally

Requires PHP 8.0 or later with the standard session and JSON extensions.

```sh
php -S 127.0.0.1:8000
```

Open `http://127.0.0.1:8000`. Copy `.env.example` to `.env` and configure
`ADMIN_EMAIL` and a strong `ADMIN_PASSWORD` to enable administrator sign-in.
Customers can create accounts through the Sign Up page.

## Deploy to Render

The repository includes a Render Blueprint and Docker configuration. To deploy:

1. Push the repository to GitHub and create a Blueprint at
   <https://dashboard.render.com/blueprints>.
2. Connect this repository and apply the `render.yaml` Blueprint.
3. Enter `ADMIN_EMAIL` and a unique, strong `ADMIN_PASSWORD` when prompted.
4. Wait for the deploy and health check to pass. Render will provide an
   always-on `onrender.com` URL. Add a custom domain in the Render dashboard
   if desired.

The Blueprint uses a paid always-on web service and a persistent disk mounted
at `/var/data`, so customer accounts, orders, and manual debt records survive
deploys and restarts. Admins can add debt records, track outstanding totals,
and mark debts as paid from the dashboard. Inventory is shared between the
customer and admin dashboards; adding bread to a cart reserves available stock,
and removing it from the cart or logging out returns that stock.
The service is intentionally not configured for Render's free tier, which can
spin down while idle and does not provide the required persistent storage.
The Forgot Password page supports customer and admin password changes. In
normal operation, the account type, email, and current password must be
verified before the New Password and Confirm Password form appears. Admin
password changes are saved as a hash in the persistent data directory and take
precedence over `ADMIN_PASSWORD` for subsequent logins. Admins can also reset
customer passwords from the dashboard's Customer Accounts section.

For a local demo only, setting `APP_ENV=demo` and
`DEMO_PASSWORD_RESET=true` enables the email-only flow: a registered email
opens the new-password form without current-password verification. This is
not identity verification and must never be enabled on a public or production
deployment. Both settings default to the safe, disabled state.

Never put production credentials in Git or in the Docker image. If credentials
were committed previously, rotate them; deleting the file in a new commit does
not remove its contents from Git history.