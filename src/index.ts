import { defineExtension } from "@tracht-digital-solutions/tds-frontend-contract";

/**
 * Visitenkarten — business-card pages.
 *
 * One linktree-style page per customer: created here, served by
 * `tds-card-frontend` on the customer's own domain (and always also at
 * `karte.tracht-digital.de/<slug>`, so a card is shown before anyone's DNS is
 * arranged).
 *
 * The `settings` slot holds the connection to that site — one pairing for all
 * cards, because one app answers every customer domain. It is deliberately not
 * on the editing screen: connecting a site is something you do once, and
 * connection fields above the content are noise at best and an invitation to
 * break a working site at worst. Same split as the blog's registry.
 */
export default defineExtension({
  id: "cards",
  name: "Visitenkarten",
  version: "0.1.0",
  permissions: [
    { id: "cards:read", label: "Visitenkarten ansehen", group: "cards" },
    { id: "cards:write", label: "Visitenkarten bearbeiten", group: "cards" },
  ],
  nav: [
    {
      id: "cards",
      label: "Visitenkarten",
      href: "/visitenkarten",
      icon: "id-card",
      // Content, not tools: a card is a published page, and it belongs beside
      // the blog and the website copy rather than beside the utilities.
      group: "content",
      order: 140,
      permission: "cards:read",
    },
  ],
  widgets: [
    {
      id: "cards-widget",
      title: "Visitenkarten",
      island: "@tracht-digital-solutions/tds-ext-cards/widgets/Widget.astro",
      size: "sm",
      permission: "cards:read",
      dataEndpoint: "/cards/summary",
      order: 140,
    },
  ],
  settings: [
    {
      id: "cards",
      label: "Visitenkarten",
      island: "@tracht-digital-solutions/tds-ext-cards/islands/Settings.astro",
      order: 140,
    },
  ],
  routes: [
    {
      pattern: "/visitenkarten",
      entrypoint: "@tracht-digital-solutions/tds-ext-cards/pages/Index.astro",
      permission: "cards:read",
    },
  ],
  i18n: {
    de: {
      "cards.title": "Visitenkarten",
      "cards.empty": "Noch keine Visitenkarte angelegt.",
    },
    en: {
      "cards.title": "Business cards",
      "cards.empty": "No business card yet.",
    },
  },
});
