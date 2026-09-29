// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { primeRuntimeConfig } from "@tracht-digital-solutions/tds-shared/api";
import { resetCache } from "@tracht-digital-solutions/tds-shared/data";
import { TOAST_EVENT } from "@tracht-digital-solutions/tds-shared/toast";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";

import CardsList from "./CardsList";

/**
 * The business-card editor. What can actually go wrong here:
 *
 *  - every call must be ABSOLUTE, on the API host: the panel is served from a
 *    host whose SPA fallback answers `200` with HTML, so a relative path
 *    produces a calm, permanent empty state instead of an error,
 *  - a save must SPREAD the card — a form that rebuilds the payload from the
 *    inputs it renders blanks every field it does not,
 *  - a cache report must be reported as what it is: `not_configured` is the
 *    normal state before pairing and must not read as "neu gebaut",
 *  - an image upload must be multipart WITHOUT a JSON content type, and a
 *    rejected one (413/415) belongs in the flow rather than in a toast,
 *  - a selection the user made must survive the next refresh.
 */

type Hit = { status?: number; body?: unknown };
let handlers: Array<(url: string, init?: RequestInit) => Hit | undefined> = [];
let calls: Array<{ url: string; method: string; init?: RequestInit }> = [];

const pathOf = (url: string) => String(url).replace(/^https?:\/\/[^/]+/i, "");

function respond(match: RegExp, body: unknown, status = 200, method?: string) {
  handlers.unshift((url, init) => {
    if (!match.test(pathOf(url))) return undefined;
    if (method && (init?.method ?? "GET") !== method) return undefined;
    return { status, body };
  });
}

let toasts: Array<{ variant: string; message: string }> = [];
const collectToast = (e: Event) => {
  toasts.push((e as CustomEvent<{ variant: string; message: string }>).detail);
};

const card = (overrides: Record<string, unknown> = {}) => ({
  id: 1,
  slug: "mira-markt",
  domain: "mira-markt.de",
  lang: "de",
  displayName: "Mira Baum",
  role: "Inhaberin",
  companyName: "Mira Markt",
  tagline: "Regional einkaufen",
  phone: "+49 30 123456",
  mobile: null,
  email: "hallo@mira-markt.de",
  website: "https://mira-markt.de",
  addressLine: "Marktstraße 3",
  postalCode: "10115",
  city: "Berlin",
  country: "DE",
  accent: "#1f3a5f",
  surface: "paper",
  theme: "light",
  metaDescription: null,
  blocks: [{ type: "links", label: null, items: [{ label: "Anrufen", href: "tel:+4930123456" }] }],
  assets: [],
  draft: true,
  publishedAt: null,
  updatedAt: "2026-09-29 08:00:00",
  ...overrides,
});

beforeEach(() => {
  resetCache();
  toasts = [];
  window.addEventListener(TOAST_EVENT, collectToast);
  handlers = [];
  calls = [];
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string, init?: RequestInit) => {
      calls.push({ url, method: init?.method ?? "GET", init });
      for (const h of handlers) {
        const hit = h(url, init);
        if (hit) {
          const status = hit.status ?? 200;
          return { ok: status >= 200 && status < 300, status, json: async () => hit.body ?? {} } as Response;
        }
      }
      return { ok: true, status: 200, json: async () => ({}) } as Response;
    }),
  );
});

afterEach(() => {
  window.removeEventListener(TOAST_EVENT, collectToast);
  cleanup();
  resetCache();
});

// apiFetch consults the host-side runtime config before it resolves a URL, so
// without this the first entry in fetch.mock.calls is that probe rather than the
// endpoint under test. The panel products never ship the file — they render a
// meta tag instead — so "absent" is also what happens in production.
beforeEach(() => primeRuntimeConfig(null));

const user = () => userEvent.setup({ delay: null });

async function renderCards(cards: unknown[] = [card()]) {
  respond(/^\/cards$/, { cards });
  render(<CardsList />);
  await waitFor(() => expect(calls.some((c) => pathOf(c.url) === "/cards")).toBe(true));
  await screen.findByText(/Neue Visitenkarte/);
}

describe("listing", () => {
  it("reads the cards from the API host, with credentials", async () => {
    await renderCards();
    // Every other assertion matches the PATH, which a relative fetch satisfies
    // just as well. This is the one that fails if a call goes back to the
    // panel's own origin.
    expect(calls[0]!.url.startsWith("https://api.tracht-digital.de/")).toBe(true);
    expect(calls[0]!.init).toMatchObject({ credentials: "include" });
  });

  it("says a card is a draft rather than showing it as live", async () => {
    await renderCards();
    expect(await screen.findByText("Entwurf")).toBeTruthy();
  });

  it("shows both addresses, because only one of them works today", async () => {
    await renderCards();
    // A screen that showed only the customer domain would make a card look
    // unpublishable until somebody else's DNS is arranged.
    expect(screen.getByText(/karte\.tracht-digital\.de\/mira-markt/)).toBeTruthy();
    expect((screen.getByLabelText("Eigene Domain") as HTMLInputElement).value).toBe("mira-markt.de");
  });

  it("keeps a chosen card selected when the list refreshes", async () => {
    const u = user();
    await renderCards([card(), card({ id: 2, slug: "nordholz", displayName: "Jens Nordholz" })]);
    await u.click(screen.getByRole("button", { name: /Jens Nordholz/ }));
    expect(await screen.findByRole("heading", { name: "Jens Nordholz" })).toBeTruthy();

    // The selection is derived from the data rather than written by an effect —
    // an effect that applies the default after a fetch lands overwrites a click
    // made in between.
    respond(/^\/cards$/, { cards: [card(), card({ id: 2, slug: "nordholz", displayName: "Jens Nordholz" })] });
    await waitFor(() => expect(screen.getByRole("heading", { name: "Jens Nordholz" })).toBeTruthy());
  });
});

describe("saving", () => {
  it("sends every field, not only the ones it re-rendered", async () => {
    const u = user();
    await renderCards();
    respond(/^\/cards\/mira-markt$/, { card: card(), cache_status: "refreshed", cached: true }, 200, "PUT");

    await u.clear(screen.getByLabelText("Funktion"));
    await u.type(screen.getByLabelText("Funktion"), "Geschäftsführerin");
    await u.click(screen.getByRole("button", { name: "Speichern" }));

    await waitFor(() => expect(calls.some((c) => c.method === "PUT")).toBe(true));
    const sent = JSON.parse(String(calls.find((c) => c.method === "PUT")!.init!.body)) as Record<string, unknown>;

    expect(sent.role).toBe("Geschäftsführerin");
    // The fields nobody touched have to travel too: a payload rebuilt from the
    // inputs the form happened to render is how live content gets blanked.
    expect(sent.displayName).toBe("Mira Baum");
    expect(sent.city).toBe("Berlin");
    expect(sent.blocks).toHaveLength(1);
  });

  it("publishes by sending draft=false, and keeps that out of the save button", async () => {
    const u = user();
    await renderCards();
    respond(/^\/cards\/mira-markt$/, { card: card(), cache_status: "refreshed", cached: true }, 200, "PUT");

    await u.click(screen.getByRole("button", { name: "Speichern und veröffentlichen" }));
    await waitFor(() => expect(calls.some((c) => c.method === "PUT")).toBe(true));

    const sent = JSON.parse(String(calls.find((c) => c.method === "PUT")!.init!.body)) as { draft: boolean };
    expect(sent.draft).toBe(false);
  });

  it("does not call an unconfigured cache a rebuild", async () => {
    const u = user();
    await renderCards();
    respond(/^\/cards\/mira-markt$/, { card: card(), cache_status: "not_configured", cached: false }, 200, "PUT");

    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(toasts.length).toBeGreaterThan(0));

    // The worst version of this is a green "neu gebaut" while nothing was sent.
    expect(toasts.at(-1)!.message).toMatch(/nicht verbunden/);
    // Not green: the card is stored and the public page is unchanged, which is
    // neither a success nor a failure.
    expect(toasts.at(-1)!.variant).toBe("warning");
  });

  it("puts a rejected value in the flow, not in a toast", async () => {
    const u = user();
    await renderCards();
    respond(/^\/cards\/mira-markt$/, { error: "Diese Domain gehört schon zu einer anderen Karte." }, 422, "PUT");

    await u.click(screen.getByRole("button", { name: "Speichern" }));

    // A message somebody has to act on must stay on screen.
    expect(await screen.findByRole("alert")).toHaveProperty(
      "textContent",
      "Diese Domain gehört schon zu einer anderen Karte.",
    );
  });

  it("reports a transport failure with its status", async () => {
    const u = user();
    await renderCards();
    handlers.unshift((url, init) => {
      if (pathOf(url) === "/cards/mira-markt" && init?.method === "PUT") throw new Error("Failed to fetch");
      return undefined;
    });

    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(toasts.length).toBeGreaterThan(0));
    expect(toasts.at(-1)!.message).toMatch(/Failed to fetch/);
  });
});

describe("images", () => {
  it("uploads multipart, without a JSON content type", async () => {
    const u = user();
    await renderCards();
    respond(/^\/cards\/mira-markt\/image\/portrait$/, { ok: true }, 201, "POST");

    const file = new File(["not really a png"], "portrait.png", { type: "image/png" });
    await u.upload(screen.getByLabelText("Portrait hochladen") as HTMLInputElement, file);

    await waitFor(() => expect(calls.some((c) => c.method === "POST")).toBe(true));
    const upload = calls.find((c) => c.method === "POST")!;
    // A hand-set Content-Type strips the multipart boundary and the server sees
    // no file at all.
    expect((upload.init?.headers ?? {}) as Record<string, string>).not.toHaveProperty("Content-Type");
    expect(upload.init?.body).toBeInstanceOf(FormData);
  });

  it("explains a refused image instead of toasting it away", async () => {
    const u = user();
    await renderCards();
    respond(/^\/cards\/mira-markt\/image\/portrait$/, { error: "Nur PNG, JPEG oder WebP." }, 415, "POST");

    // A .png the SERVER refuses — the browser filters an .svg out at the input's
    // `accept`, so it would never reach the endpoint under test.
    const file = new File(["nope"], "portrait.png", { type: "image/png" });
    await u.upload(screen.getByLabelText("Portrait hochladen") as HTMLInputElement, file);

    expect(await screen.findByText("Nur PNG, JPEG oder WebP.")).toBeTruthy();
  });

  it("links a stored image on the API host", async () => {
    await renderCards([card({ assets: ["portrait"] })]);
    const link = (await screen.findByRole("link", { name: "Ansehen" })) as HTMLAnchorElement;
    // A relative href would ask the panel's host for the bytes and get HTML.
    expect(link.href).toBe("https://api.tracht-digital.de/cards/mira-markt/image/portrait");
  });
});
