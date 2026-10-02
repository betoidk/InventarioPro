# InventarioPro — Product Context

## Vision
Real-time inventory management for small and medium retail: visibility, speed, and trust in one interface.

## Core Users
- Retail shop owners / managers
- Store staff (inventory tracking)
- Multi-location operators (future: sync across locations)

## Problems Solved
1. **Manual stock tracking** — No more spreadsheets or guesswork. Real-time product counts.
2. **Lost inventory visibility** — Know what's in stock, at a glance, across categories.
3. **Dead time on inventory checks** — Add/edit/delete in seconds, not minutes.

## Platform & Constraints
- **Web only** — Desktop-first, responsive down to mobile-viewing (not mobile-editing priority)
- **MVP scope** — Categories + Products with full CRUD. No auth, no multi-user yet. No reports/exports.
- **Success metric** — Speed (sub-100ms API responses) + Precision (no data loss, accurate counts)

## Design Direction
- **Incumbent world** — Apple Design System (translucent materials, glassmorphism, spring animations, dark/light modes)
- **Exploration open** — Test alternative color palettes alongside Apple's. Brief: something that feels premium, trustworthy, and efficient—not sterile.
- **Not persuade** — This is **Operate** mode: tasks matter more than delight. Scanability and native expectations win.

## Roadmap (not MVP)
- User authentication
- Multi-location sync
- Stock alerts / low-inventory warnings
- Bulk import/export
- Advanced search and filters
- Mobile app (iOS/Android)

## Success Definition
Users complete inventory tasks (add product, update stock, search category) in 10s or less. Data is accurate. No crashes.

---

**Platform:** Web (PHP + MySQL backend, vanilla HTML/CSS/JS frontend)  
**Mode:** Operate (task completion, real-time feedback, precision)  
**Next:** Run `impeccable document` to extract design tokens into DESIGN.md, then refine visual direction.
