1. Consistency Trade-off

Online sales may use a Saga/event-driven model, where downstream stock synchronization can be eventually consistent, typically within approximately one second.

This is acceptable for non-critical synchronization and reporting operations.

However, the stock availability check and stock reservation/decrement for an online sale must remain strongly consistent to prevent overselling.

Required behavior:

Online Sale
│
▼
Validate Stock
│
▼
Reserve / Decrement Stock
│
├── Success ──► Complete Sale
│
└── Failure ──► Reject Sale
