# Repository Working Guide

## Project and stack

This repository contains the Donation Management System for Al-Kalim Al-Tayyib Charity Organisation. It uses Laravel 12, PHP 8.2+, MySQL/MariaDB, Blade, Bootstrap, Tailwind where already present, and vanilla JavaScript. The interface is bilingual (Arabic and English), and the primary currency is Sudanese Pound (SDG / ج.س).

Before implementing any feature, read `docs/PROJECT_REQUIREMENTS.md` and treat it as the approved scope. Preserve the existing public interface unless the task explicitly requests a UI change.

## Common commands

- Install PHP dependencies: `composer install`
- Install frontend dependencies: `npm ci`
- Use `npm install` only when intentionally adding, removing, or updating frontend dependencies.
- Start the development processes: `composer run dev`
- Run the test suite: `composer test` or `php artisan test`
- Run a focused test: `php artisan test --filter=TestName`
- Format PHP: `vendor/bin/pint`
- Build frontend assets: `npm run build`

Do not run migrations, seeders, destructive database commands, or the `composer setup` script unless explicitly authorized. The setup script includes a forced migration.

## Architecture and workflow

- Work incrementally and modify only files necessary for the requested task; avoid unrelated refactoring.
- Follow Laravel conventions: migrations, Eloquent relationships, Form Requests, Policies, middleware, database transactions, factories, seeders, and feature tests.
- Use MySQL/MariaDB as the operational database; do not introduce BigQuery.
- Keep controllers thin and place business rules where they can be validated and tested consistently.
- Keep authorization extensible for future administrator specialization by category.
- Maintain compatibility with the existing bilingual, responsive, accessible UI.
- Use a simulated academic Sandbox Checkout only; do not integrate a real payment gateway.

## Security and data rules

- Enforce roles through middleware, Policies, and server-side checks. Public registration must never create `admin` or `super_admin` accounts.
- Validate input with Form Requests and retain Laravel CSRF protection and password hashing.
- Store sensitive beneficiary documents privately outside the public web root, and authorize every download to the owner or an authorized administrator.
- Validate uploads by extension, MIME type, size, and safe filename.
- Never store full card numbers, CVV, or real payment details.
- Use database transactions and idempotency protection for payment and refund state changes.
- Apply rate limits to sensitive actions, mask recipient account identifiers, maintain audit logs, and use soft deletion where recovery is required.
- Never expose, print, or commit `.env` values, credentials, or other secrets.

## Testing

- Add or update automated feature tests for every critical workflow and authorization boundary changed by a task.
- Cover success, validation failure, forbidden access, lifecycle transitions, financial totals, idempotency, and private-file access where applicable.
- Use factories and seeders for deterministic test and demonstration data. Run the narrowest relevant tests first, then the broader suite when practical.

## Git rules

- Inspect the working tree before editing and preserve unrelated user changes.
- Never commit, push, rewrite history, or discard changes unless explicitly requested.
- Never perform destructive database or filesystem operations without explicit authorization.
