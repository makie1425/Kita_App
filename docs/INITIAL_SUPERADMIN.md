# Initial Super Admin on Render Free

Render Free does not provide a shell. After the database migrations have completed, add these private environment variables to the Render web service:

| Variable | Value |
| --- | --- |
| `SEED_INITIAL_SUPERADMIN` | `true` for the provisioning deploy |
| `INITIAL_SUPERADMIN_EMAIL` | An email address you control |
| `INITIAL_SUPERADMIN_PASSWORD` | A new, private password of 12–72 characters |

Keep `RUN_MIGRATIONS=false` after the initial schema setup. Save and deploy. The startup command creates the first active Super Admin only if no active Super Admin exists. Repeated starts leave the existing account and password unchanged. It will not overwrite an account with another role.

On an otherwise empty installation, the command also creates the initial organization and workspace and grants the Super Admin an explicit account-management membership. It does not assign historical accounts or business records to that workspace.

After the deploy logs show `Initial Super Admin is available`, set `SEED_INITIAL_SUPERADMIN=false`, remove both `INITIAL_SUPERADMIN_*` variables, and deploy again. The account remains in MySQL. Super Admin login also requires working email OTP delivery; configure the HTTPS mail driver as described in [OTP_EMAIL.md](OTP_EMAIL.md).

Never put these values in the repository or paste the password into a chat or support ticket. If a password has been disclosed, choose a different one.
