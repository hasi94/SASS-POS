# FlexiPOS frontend

Angular 21 workspace: two applications over one shared library (docs/architecture.md §15).

| Project | What it is | Dev command |
|---|---|---|
| `projects/pos` | Till application | `npm start -- pos` |
| `projects/admin` | Admin and ERP application | `npm start -- admin` |
| `projects/shared` | `@flexipos/shared`: API client, auth, tenant and branch context, sign-in screens | tests only |

```bash
npm ci
npm test                 # all three projects, once
npx ng build pos         # production build
npx ng build admin
```

`@flexipos/shared` is mapped to the library source in `tsconfig.json`, so no separate library
build is needed while developing.

## Working before the backend exists

A development build runs the **mock backend** (`projects/shared/src/lib/mock/mock-backend.ts`).
It answers the auth endpoints below in the real response envelope. Demo sign-in:
`demo@flexipos.test` / `demo1234`; two companies, one of them with two branches.

It is wired through `src/environments/`: only `environment.development.ts` imports it, so a
production build does not contain it. Check this after changing either file:

```bash
npx ng build pos && grep -l "demo@flexipos.test" dist/pos/browser/*.js   # must print nothing
```

When a real endpoint exists, delete its branch from the mock and nothing else changes.

## Assumed API contract (to reconcile with FP-215 and the auth module)

All replies use the envelope `{ success, data, message, errors[{code, field, detail}], meta{request_id} }`.

| Call | Body | `data` |
|---|---|---|
| `POST /api/v1/auth/login` | `email`, `password` | `token`, `user`, `tenants[{id, name, branches[{id, name}]}]` |
| `POST /api/v1/auth/select-tenant` | `tenant_id` | `token` (now bound to that tenant), `tenant` |
| `POST /api/v1/auth/logout` | — | `{}` |

The client names a tenant but never a database; the server verifies access and resolves the
connection (Refference/ARCH.md §5). These paths and shapes are the frontend's assumption, not an
agreed contract. Change them here and in `auth.service.ts` when the backend lands.

## Not built yet

Service worker and manifest (PWA), offline database and sync (FP-237), plugin remotes, the
shared tax and discount engine (FP-231), translations, and a CI job for this workspace.
