# UA QMS Deployment Plan

This is the practical step-by-step plan for deploying this project with:

- GitHub
- Supabase
- Vercel

## Important reality first

This system is currently:

- PHP
- `mysqli`
- MySQL SQL scripts
- local file uploads
- PHP sessions

Supabase is PostgreSQL. Vercel can run PHP through the `vercel-php` runtime, but this app will not deploy cleanly to Vercel + Supabase without migration work.

So the honest plan is:

1. Put the code in GitHub
2. Prepare and migrate the database to Supabase
3. Refactor the PHP app from MySQL to PostgreSQL/Supabase
4. Move uploads to cloud storage
5. Deploy the migrated app to Vercel

## Phase 1: Prepare the repository

### Step 1: Review sensitive files

Before pushing to GitHub, check for:

- database passwords
- API keys
- local-only config
- uploaded files you do not want in the repo

This project currently has hard-coded local DB values in `config/db.php`.

### Step 2: Add a `.gitignore`

If you do not already have one, create a `.gitignore` and exclude:

- `.env`
- `uploads/`
- local cache/temp files
- editor files

Suggested entries:

```gitignore
.env
.env.local
uploads/
tmp/
.idea/
.vscode/
node_modules/
vendor/
```

### Step 3: Initialize Git if needed

From the project root:

```powershell
git init -b main
git add .
git commit -m "Initial commit"
```

### Step 4: Create the GitHub repository

On GitHub:

1. Create a new empty repository
2. Do not initialize it with a README
3. Copy the remote URL

### Step 5: Push to GitHub

```powershell
git remote add origin <YOUR_GITHUB_REPO_URL>
git push -u origin main
```

Official GitHub docs:

- Adding local code to GitHub: https://docs.github.com/en/migrations/importing-source-code/using-the-command-line-to-import-source-code/adding-locally-hosted-code-to-github
- Repository management: https://docs.github.com/en/repositories/creating-and-managing-repositories

## Phase 2: Prepare the database

### Step 6: Use the bundled MySQL export

This project already has a bundled MySQL deployment script:

- `deployment/mysql-export/ua_qms_mysql_bundle.sql`

That file chains the current schema and migration scripts for MySQL.

### Step 7: Understand the migration gap

Supabase is PostgreSQL, so this SQL bundle cannot be imported directly as-is.

You must convert or migrate these MySQL-specific features:

- `AUTO_INCREMENT`
- `ENUM`
- `YEAR`
- `ON UPDATE CURRENT_TIMESTAMP`
- MySQL `ALTER TABLE ... IF NOT EXISTS`
- MySQL procedures and triggers
- `SOURCE`

### Step 8: Create a Supabase project

In Supabase:

1. Create a new project
2. Save the following:
   - Project URL
   - Database password
   - Database host
   - Session pooler connection string
   - Direct connection string

Official Supabase docs:

- MySQL migration guide: https://supabase.com/docs/guides/platform/migrating-to-supabase/mysql
- Migration overview: https://supabase.com/docs/guides/platform/migrating-to-supabase
- Import data guide: https://supabase.com/docs/guides/database/import-data

### Step 9: Migrate the schema and data

Choose one approach:

1. Supabase migration tool from MySQL
2. `pgloader`
3. Manual PostgreSQL rewrite plus data import

For this project, the safest long-term approach is:

1. Start from the MySQL schema bundle
2. Rewrite the schema for PostgreSQL
3. Import seed/reference data
4. Validate every table before changing app code

### Step 10: Validate the migrated database

Check these tables carefully:

- `offices`
- `users`
- `document_types`
- `documents`
- `document_reviews`
- `target_submissions`
- `system_settings`
- `notifications`
- `user_notifications`
- `academic_years`
- `semesters`
- `messages`
- `conversations`
- `conversation_participants`
- `repository_views`
- `repository_access_logs`

Also confirm:

- foreign keys work
- text encodings are correct
- dates and timestamps migrated correctly
- status values still match the PHP code expectations

## Phase 3: Refactor the app for Supabase/Postgres

### Step 11: Replace MySQL connection code

Current file:

- `config/db.php`

Current behavior:

- uses `new mysqli(...)`

You need to replace this with either:

1. PHP PostgreSQL access using `pg_*` or PDO PostgreSQL
2. Supabase APIs

For this codebase, PDO PostgreSQL is the more natural first migration.

### Step 12: Update SQL queries

You will need to update queries across:

- `dashboard/`
- `api/`
- `auth/`
- `includes/`

Look especially for:

- MySQL date functions
- `LIMIT` assumptions that may still work but need review
- MySQL JSON handling
- `ENUM` assumptions
- `mysqli_sql_exception`

### Step 13: Move uploads out of local storage

This app currently stores files locally under the project.

That is not a good fit for Vercel because the runtime filesystem is not for persistent app uploads.

Use:

- Supabase Storage

You will need to refactor:

- upload handlers
- preview/file URLs
- repository access paths

### Step 14: Review sessions and auth

This app uses PHP sessions.

Before Vercel deployment, verify:

- session persistence works correctly
- login/logout works across deployments
- redirects still work

If session behavior becomes unstable, move session storage to a persistent backend.

## Phase 4: Prepare Vercel

### Step 15: Confirm PHP runtime strategy

Vercel supports PHP through the `vercel-php` runtime.

Official docs:

- Runtimes: https://vercel.com/docs/functions/runtimes
- Runtime configuration: https://vercel.com/docs/functions/configuring-functions/runtime
- PHP runtime repo: https://github.com/vercel-community/php

### Step 16: Add environment variables

In Vercel Project Settings, add environment variables such as:

```text
DB_HOST=
DB_PORT=
DB_NAME=
DB_USER=
DB_PASS=
SUPABASE_URL=
SUPABASE_ANON_KEY=
SUPABASE_SERVICE_ROLE_KEY=
APP_ENV=production
```

Official Vercel docs:

- Deployments overview: https://vercel.com/docs/deployments/overview
- Git deployments: https://vercel.com/docs/deployments/git
- GitHub integration: https://vercel.com/docs/deployments/git/vercel-for-github
- Environment variables: https://vercel.com/docs/environment-variables

### Step 17: Connect GitHub to Vercel

In Vercel:

1. Import project from GitHub
2. Select your repository
3. Configure the root directory if needed
4. Set all required environment variables
5. Deploy

## Phase 5: Post-deploy verification

### Step 18: Test the app in this order

1. Homepage and login
2. Session persistence after login
3. Dashboard pages
4. Submission flow
5. Review flow
6. File upload
7. File preview/download
8. Manage accounts
9. System configuration
10. Reports and repository pages

### Step 19: Check for production-only failures

Pay close attention to:

- upload failures
- broken file paths
- session loss
- DB connection issues
- query syntax differences
- timeout issues on larger pages

## Best execution order for this exact project

If your goal is the target stack you asked for, use this order:

1. Push current code to GitHub
2. Keep the code versioned as-is
3. Migrate MySQL schema/data to Supabase Postgres
4. Refactor PHP DB access from `mysqli` to PostgreSQL
5. Move uploaded files to Supabase Storage
6. Test locally against Supabase
7. Deploy to Vercel
8. Run full smoke tests

## Short recommendation

If you want the fastest live deployment:

- Use GitHub
- Use a PHP-friendly host
- Use MySQL/MariaDB first

If you want the modern target stack you requested:

- Treat Vercel + Supabase as a migration project, not a direct deployment

## Next practical task

The next strongest move is one of these:

1. Create a PostgreSQL-compatible schema from the current MySQL bundle
2. Refactor `config/db.php` and one module at a time for Supabase/Postgres
3. Add a `vercel.json` and `.env.example` as the start of the Vercel deployment setup
