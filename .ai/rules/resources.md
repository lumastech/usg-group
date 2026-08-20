---
paths:
  - 'app/Http/Resources/**'
---

# Resources

## Single resources are unwrapped; collections keep data/meta
AppServiceProvider calls JsonResource::withoutWrapping(), so `new MemberResource($m)` arrives at Inertia as the prop itself (no `.data`), while `MemberResource::collection($paginator)` still sends `data` + `meta` — which is exactly what DataTable reads. Vue props must be typed to match: `member: Member` but `members: { data: Member[]; meta: PaginationMeta }`.

Every resource carries an `abilities` map computed from real policy calls, so screens render buttons from the server's answers rather than re-deriving permissions on the client.
