import { useEffect, useMemo, useState } from "react";

import { apiFetch, apiUrl } from "@tracht-digital-solutions/tds-shared/api";
import { ConfirmDialog, Spinner, toast } from "@tracht-digital-solutions/tds-shared/components";
import { invalidate, staleClass, useCachedJson } from "@tracht-digital-solutions/tds-shared/data";
import {
  emptyCardDocument,
  type CardBlock,
} from "@tracht-digital-solutions/tds-shared/schemas";

import BlockList from "./BlockList.tsx";

/**
 * Visitenkarten — the content screen: pick a card, edit it, publish it.
 *
 * ### Two addresses, and why both are on this screen
 *
 * A card always has `karte.tracht-digital.de/<slug>`, and may also have the
 * customer's own domain. The slug works the moment the card exists; the domain
 * waits for somebody else's DNS. Showing only the domain field would make a card
 * look unpublishable for as long as that takes.
 *
 * ### The rules this screen follows, each with a scar behind it
 *
 * - **`apiFetch`, never a relative `fetch`.** A relative path hits the panel's
 *   own host, which answers `200` with SPA HTML — producing a calm, permanent
 *   empty state rather than an error.
 * - **Stale-while-revalidate stays visibly stale.** A refresh that fails keeps
 *   the data and adds a banner; it never empties the list, and it never discards
 *   what somebody is typing.
 * - **Spread, never replace.** Every field edit is `{ ...draft, x }`.
 * - **A cache report is factual.** The API says whether a rebuild request really
 *   went out; `not_configured` is the normal state before pairing and must read
 *   as such, not as a failure and not as success.
 * - **No `ToastHost` here.** The panel host owns the only one.
 */

interface Card {
  id: number;
  slug: string;
  domain: string | null;
  lang: string;
  displayName: string;
  role: string | null;
  companyName: string | null;
  tagline: string | null;
  phone: string | null;
  mobile: string | null;
  email: string | null;
  website: string | null;
  addressLine: string | null;
  postalCode: string | null;
  city: string | null;
  country: string | null;
  accent: string;
  surface: string;
  theme: string;
  metaDescription: string | null;
  blocks: CardBlock[];
  assets: string[];
  draft: boolean;
  publishedAt: string | null;
  updatedAt: string;
}

interface CacheReport {
  cache_status?: string;
  cached?: boolean;
}

const SURFACES = ["paper", "ink", "navy", "sand"] as const;
const THEMES = ["light", "dark"] as const;

/**
 * What a save did to the public pages, in words that are true — and in the
 * VARIANT that is true.
 *
 * A save whose rebuild never went out is not a success and not a failure: the
 * card is stored, the public page is unchanged. Reporting it green is the lie
 * this function exists to avoid, so it returns the colour as well as the words.
 */
function cacheNote(report: CacheReport): { variant: "success" | "warning"; message: string } {
  switch (report.cache_status) {
    case "refreshed":
      return {
        variant: "success",
        message: report.cached ? "Gespeichert. Die Seiten wurden neu gebaut." : "Gespeichert. Neu bauen wurde angefragt.",
      };
    case "not_configured":
      return {
        variant: "warning",
        message: "Gespeichert. Die Kartenseite ist noch nicht verbunden — die öffentlichen Seiten sind unverändert.",
      };
    case "failed":
      return { variant: "warning", message: "Gespeichert. Das Neubauen hat nicht geklappt." };
    default:
      return { variant: "success", message: "Gespeichert." };
  }
}

export default function CardsList() {
  const cardsQuery = useCachedJson<{ cards: Card[] }>("/cards");
  const cards = cardsQuery.data?.cards ?? [];
  const listStale = cardsQuery.stale && cards.length > 0;

  const [selectedSlug, setSelectedSlug] = useState<string | null>(null);

  // The selection is DERIVED from what arrived, not synced in an effect. An
  // effect that writes the default after the data lands overwrites a click the
  // user made in between — the panel has paid for that one before.
  const selected = useMemo(
    () => cards.find((c) => c.slug === selectedSlug) ?? cards[0] ?? null,
    [cards, selectedSlug],
  );

  if (cardsQuery.loading) {
    return (
      <p aria-busy="true">
        <Spinner /> Visitenkarten werden geladen …
      </p>
    );
  }

  if (cardsQuery.error && cards.length === 0) {
    return (
      <p className="tds-alert tds-alert--danger" role="alert">
        Visitenkarten konnten nicht geladen werden ({cardsQuery.error.message}).
      </p>
    );
  }

  return (
    <div className="tds-stack">
      {cardsQuery.error ? (
        <p className="tds-alert tds-alert--danger" role="alert">
          Die Liste konnte nicht aktualisiert werden ({cardsQuery.error.message}). Was hier steht,
          ist möglicherweise veraltet.
        </p>
      ) : null}

      <CreateCard onCreated={(slug) => setSelectedSlug(slug)} />

      {cards.length === 0 ? (
        <div className="tds-empty">
          <p>Noch keine Visitenkarte angelegt.</p>
          <p className="marginalia">
            Eine Karte ist zuerst ein Entwurf. Sie geht live, sobald sie veröffentlicht wird.
          </p>
        </div>
      ) : (
        <>
          {cards.length > 1 ? (
            <div
              className={staleClass(listStale, "tds-toolbar")}
              role="group"
              aria-label="Visitenkarte wählen"
              aria-busy={listStale}
            >
              {cards.map((card) => (
                <button
                  key={card.id}
                  type="button"
                  className={
                    card.slug === selected?.slug
                      ? "chip tds-tab chip--info"
                      : card.draft
                        ? "chip tds-tab chip--neutral"
                        : "chip tds-tab chip--success"
                  }
                  aria-pressed={card.slug === selected?.slug}
                  onClick={() => setSelectedSlug(card.slug)}
                >
                  {card.displayName || card.slug}
                  {card.draft ? " (Entwurf)" : ""}
                </button>
              ))}
            </div>
          ) : null}

          {selected ? <CardEditor key={selected.slug} card={selected} /> : null}
        </>
      )}
    </div>
  );
}

/* ====================================================================== */

function CreateCard({ onCreated }: { onCreated: (slug: string) => void }) {
  const [name, setName] = useState("");
  const [slug, setSlug] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const create = async (): Promise<void> => {
    setBusy(true);
    setError(null);
    try {
      const res = await apiFetch("/cards", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ displayName: name, slug: slug || undefined }),
      });
      const payload = (await res.json().catch(() => ({}))) as { error?: string; card?: Card };
      if (!res.ok) {
        // A rejected value belongs in the flow, next to the field that caused
        // it — not in a toast that has vanished by the time it is read.
        setError(payload.error ?? `Anlegen fehlgeschlagen (${res.status}).`);
        return;
      }
      setName("");
      setSlug("");
      invalidate("/cards");
      if (payload.card) onCreated(payload.card.slug);
      toast.success("Visitenkarte angelegt. Sie ist noch ein Entwurf.");
    } catch (err) {
      // A transport failure is not a rejected value, and its message is the only
      // clue to what happened.
      toast.danger(`Anlegen fehlgeschlagen: ${err instanceof Error ? err.message : String(err)}`);
    } finally {
      setBusy(false);
    }
  };

  return (
    <form
      className="tds-card tds-stack"
      onSubmit={(e) => {
        e.preventDefault();
        void create();
      }}
    >
      <h2>Neue Visitenkarte</h2>
      {error ? (
        <p className="tds-alert tds-alert--danger" role="alert">
          {error}
        </p>
      ) : null}
      <div className="tds-row">
        <label className="tds-stack">
          <span>Name auf der Karte</span>
          <input
            className="field-boxed"
            type="text"
            value={name}
            required
            maxLength={160}
            onChange={(e) => setName(e.target.value)}
          />
        </label>
        <label className="tds-stack">
          <span>Adresse (optional)</span>
          <input
            className="field-boxed"
            type="text"
            value={slug}
            placeholder="wird aus dem Namen vorgeschlagen"
            maxLength={80}
            onChange={(e) => setSlug(e.target.value)}
          />
        </label>
        <button type="submit" className="btn btn-primary" disabled={busy || name.trim() === ""}>
          {busy ? "Wird angelegt …" : "Anlegen"}
        </button>
      </div>
    </form>
  );
}

/* ====================================================================== */

function CardEditor({ card }: { card: Card }) {
  const [draft, setDraft] = useState<Card>(card);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [confirmDelete, setConfirmDelete] = useState(false);

  // A different card means a different form. Keyed on the slug by the parent, so
  // this only runs when the API sends a newer version of the SAME card.
  useEffect(() => {
    setDraft(card);
  }, [card]);

  const set = <K extends keyof Card>(key: K, value: Card[K]): void => {
    // Spread, never replace: a form that rebuilds the object from its own inputs
    // drops every key it does not render.
    setDraft((previous) => ({ ...previous, [key]: value }));
  };

  const save = async (publish?: boolean): Promise<void> => {
    setBusy(true);
    setError(null);
    const nextDraft = publish === undefined ? draft.draft : !publish;
    try {
      const res = await apiFetch(`/cards/${draft.slug}`, {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ ...toPayload(draft), draft: nextDraft }),
      });
      const payload = (await res.json().catch(() => ({}))) as { error?: string } & CacheReport;
      if (!res.ok) {
        setError(payload.error ?? `Speichern fehlgeschlagen (${res.status}).`);
        return;
      }
      invalidate("/cards");
      const note = cacheNote(payload);
      toast[note.variant](note.message);
    } catch (err) {
      toast.danger(`Speichern fehlgeschlagen: ${err instanceof Error ? err.message : String(err)}`);
    } finally {
      setBusy(false);
    }
  };

  const remove = async (): Promise<void> => {
    setConfirmDelete(false);
    setBusy(true);
    try {
      const res = await apiFetch(`/cards/${draft.slug}`, { method: "DELETE" });
      if (!res.ok) {
        setError(`Löschen fehlgeschlagen (${res.status}).`);
        return;
      }
      invalidate("/cards");
      toast.success("Visitenkarte gelöscht.");
    } catch (err) {
      toast.danger(`Löschen fehlgeschlagen: ${err instanceof Error ? err.message : String(err)}`);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="tds-card tds-stack">
      <div className="tds-row">
        <h2>{draft.displayName || draft.slug}</h2>
        <span className={draft.draft ? "chip chip--neutral" : "chip chip--success"}>
          {draft.draft ? "Entwurf" : "Veröffentlicht"}
        </span>
      </div>

      {error ? (
        <p className="tds-alert tds-alert--danger" role="alert">
          {error}
        </p>
      ) : null}

      <fieldset className="tds-stack">
        <legend>Adressen</legend>
        <p className="marginalia">
          Die Karte ist immer unter <code>karte.tracht-digital.de/{draft.slug}</code> erreichbar. Die
          eigene Domain kommt dazu, sobald sie im Hosting eingerichtet ist.
        </p>
        <label className="tds-stack">
          <span>Eigene Domain</span>
          <input
            className="field-boxed"
            type="text"
            value={draft.domain ?? ""}
            placeholder="mira-markt.de"
            maxLength={190}
            onChange={(e) => set("domain", e.target.value || null)}
          />
        </label>
      </fieldset>

      <fieldset className="tds-stack">
        <legend>Feste Felder</legend>
        <Text label="Name" value={draft.displayName} onChange={(v) => set("displayName", v)} max={160} />
        <Text label="Funktion" value={draft.role} onChange={(v) => set("role", v)} max={160} />
        <Text label="Firma" value={draft.companyName} onChange={(v) => set("companyName", v)} max={160} />
        <Text label="Kurzsatz" value={draft.tagline} onChange={(v) => set("tagline", v)} max={240} />
        <Text label="Telefon" value={draft.phone} onChange={(v) => set("phone", v)} max={60} />
        <Text label="Mobil" value={draft.mobile} onChange={(v) => set("mobile", v)} max={60} />
        <Text label="E-Mail" value={draft.email} onChange={(v) => set("email", v)} max={190} />
        <Text label="Website" value={draft.website} onChange={(v) => set("website", v)} max={300} />
        <Text label="Straße und Nummer" value={draft.addressLine} onChange={(v) => set("addressLine", v)} max={190} />
        <Text label="Postleitzahl" value={draft.postalCode} onChange={(v) => set("postalCode", v)} max={20} />
        <Text label="Ort" value={draft.city} onChange={(v) => set("city", v)} max={120} />
        <Text label="Land (zwei Buchstaben)" value={draft.country} onChange={(v) => set("country", v)} max={2} />
      </fieldset>

      <fieldset className="tds-stack">
        <legend>Aussehen</legend>
        <div className="tds-row">
          <label className="tds-stack">
            <span>Akzentfarbe</span>
            <input
              className="field-boxed"
              type="color"
              value={/^#[0-9a-f]{6}$/i.test(draft.accent) ? draft.accent : "#1f3a5f"}
              // Unlayered `.field-boxed { width: 100% }` from tds-shared beats
              // any width utility, so the narrow swatch has to be inline.
              style={{ width: "6rem" }}
              onChange={(e) => set("accent", e.target.value)}
            />
          </label>
          <label className="tds-stack">
            <span>Fläche</span>
            <select
              className="field-boxed"
              value={draft.surface}
              onChange={(e) => set("surface", e.target.value)}
            >
              {SURFACES.map((s) => (
                <option key={s} value={s}>
                  {s}
                </option>
              ))}
            </select>
          </label>
          <label className="tds-stack">
            <span>Hell oder dunkel</span>
            <select
              className="field-boxed"
              value={draft.theme}
              onChange={(e) => set("theme", e.target.value)}
            >
              {THEMES.map((t) => (
                <option key={t} value={t}>
                  {t === "light" ? "hell" : "dunkel"}
                </option>
              ))}
            </select>
          </label>
        </div>
      </fieldset>

      <fieldset className="tds-stack">
        <legend>Bilder</legend>
        <ImageField slug={draft.slug} kind="portrait" label="Portrait" present={draft.assets.includes("portrait")} />
        <ImageField slug={draft.slug} kind="logo" label="Logo" present={draft.assets.includes("logo")} />
      </fieldset>

      <fieldset className="tds-stack">
        <legend>Freie Blöcke</legend>
        <BlockList
          blocks={draft.blocks.length > 0 ? draft.blocks : emptyCardDocument().blocks}
          disabled={busy}
          onChange={(blocks) => set("blocks", blocks)}
        />
      </fieldset>

      <fieldset className="tds-stack">
        <legend>Suchmaschinen</legend>
        <label className="tds-stack">
          <span>Beschreibung (80–160 Zeichen)</span>
          <textarea
            className="field-boxed"
            rows={2}
            value={draft.metaDescription ?? ""}
            maxLength={200}
            onChange={(e) => set("metaDescription", e.target.value || null)}
          />
          <span className="marginalia">{(draft.metaDescription ?? "").length} Zeichen</span>
        </label>
      </fieldset>

      <div className="tds-toolbar">
        <button type="button" className="btn btn-primary" disabled={busy} onClick={() => void save()}>
          {busy ? "Wird gespeichert …" : "Speichern"}
        </button>
        {draft.draft ? (
          <button type="button" className="btn btn-accent" disabled={busy} onClick={() => void save(true)}>
            Speichern und veröffentlichen
          </button>
        ) : (
          <button type="button" className="btn btn-accent" disabled={busy} onClick={() => void save(false)}>
            Zurück auf Entwurf
          </button>
        )}
        <button type="button" className="btn btn-ghost" disabled={busy} onClick={() => setConfirmDelete(true)}>
          Löschen
        </button>
      </div>

      <ConfirmDialog
        open={confirmDelete}
        title="Visitenkarte löschen?"
        // Never `window.confirm`: it blocks the whole tab and cannot be styled,
        // announced or tested.
        message={`„${draft.displayName || draft.slug}" wird mit allen Bildern entfernt. Das lässt sich nicht zurückholen.`}
        confirmLabel="Löschen"
        onConfirm={() => void remove()}
        onCancel={() => setConfirmDelete(false)}
      />
    </div>
  );
}

/** Only the editable fields; `id`, `assets` and the timestamps are the server's. */
function toPayload(card: Card): Record<string, unknown> {
  return {
    domain: card.domain,
    lang: card.lang,
    displayName: card.displayName,
    role: card.role,
    companyName: card.companyName,
    tagline: card.tagline,
    phone: card.phone,
    mobile: card.mobile,
    email: card.email,
    website: card.website,
    addressLine: card.addressLine,
    postalCode: card.postalCode,
    city: card.city,
    country: card.country,
    accent: card.accent,
    surface: card.surface,
    theme: card.theme,
    metaDescription: card.metaDescription,
    blocks: card.blocks,
  };
}

function Text({
  label,
  value,
  onChange,
  max,
}: {
  label: string;
  value: string | null;
  onChange: (value: string | null) => void;
  max: number;
}) {
  return (
    <label className="tds-stack">
      <span>{label}</span>
      <input
        className="field-boxed"
        type="text"
        value={value ?? ""}
        maxLength={max}
        onChange={(e) => onChange(e.target.value || null)}
      />
    </label>
  );
}

/* ====================================================================== */

function ImageField({
  slug,
  kind,
  label,
  present,
}: {
  slug: string;
  kind: "portrait" | "logo";
  label: string;
  present: boolean;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const upload = async (file: File): Promise<void> => {
    setBusy(true);
    setError(null);
    try {
      const blob = await downscale(file);
      const form = new FormData();
      form.append("file", blob, blob instanceof File ? blob.name : `${kind}.webp`);
      // No Content-Type header: the browser has to set the multipart boundary,
      // and one set by hand makes the body unparseable on the server.
      const res = await apiFetch(`/cards/${slug}/image/${kind}`, { method: "POST", body: form });
      const payload = (await res.json().catch(() => ({}))) as { error?: string };
      if (!res.ok) {
        // 413 and 415 are rejected values and belong in the flow.
        setError(payload.error ?? `Hochladen fehlgeschlagen (${res.status}).`);
        return;
      }
      invalidate("/cards");
      toast.success(`${label} hochgeladen.`);
    } catch (err) {
      toast.danger(`Hochladen fehlgeschlagen: ${err instanceof Error ? err.message : String(err)}`);
    } finally {
      setBusy(false);
    }
  };

  const remove = async (): Promise<void> => {
    setBusy(true);
    try {
      const res = await apiFetch(`/cards/${slug}/image/${kind}`, { method: "DELETE" });
      if (!res.ok) {
        setError(`Entfernen fehlgeschlagen (${res.status}).`);
        return;
      }
      invalidate("/cards");
      toast.success(`${label} entfernt.`);
    } catch (err) {
      toast.danger(`Entfernen fehlgeschlagen: ${err instanceof Error ? err.message : String(err)}`);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="tds-stack">
      {error ? (
        <p className="tds-alert tds-alert--danger" role="alert">
          {error}
        </p>
      ) : null}
      <div className="tds-row">
        <span>{label}</span>
        {present ? (
          <>
            {/* Absolute: the image lives on the API host, and a relative src
                would ask the panel's own host for it and get SPA HTML. */}
            <a
              className="link-underline"
              href={apiUrl(`/cards/${slug}/image/${kind}`)}
              target="_blank"
              rel="noreferrer"
            >
              Ansehen
            </a>
            <button type="button" className="btn btn-ghost" disabled={busy} onClick={() => void remove()}>
              Entfernen
            </button>
          </>
        ) : (
          <span className="marginalia">noch keins</span>
        )}
        <input
          className="field-boxed"
          type="file"
          accept="image/png,image/jpeg,image/webp"
          disabled={busy}
          aria-label={`${label} hochladen`}
          onChange={(e) => {
            const file = e.target.files?.[0];
            if (file) void upload(file);
            e.target.value = "";
          }}
        />
      </div>
    </div>
  );
}

/**
 * Shrink a picture before it goes up.
 *
 * The server deliberately does NOT resize: the production host does not
 * guarantee `ext-gd`, so a resize there would work in development and throw on
 * the host. Doing it here also means a 12-megapixel phone photo does not travel
 * over the wire to be rejected for size.
 *
 * Falls back to the original file whenever canvas or WebP is unavailable —
 * a missing convenience, not a missing upload.
 */
async function downscale(file: File, max = 1024): Promise<Blob> {
  try {
    const bitmap = await createImageBitmap(file);
    const scale = Math.min(1, max / Math.max(bitmap.width, bitmap.height));
    const width = Math.round(bitmap.width * scale);
    const height = Math.round(bitmap.height * scale);
    const canvas = document.createElement("canvas");
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext("2d");
    if (!context) return file;
    context.drawImage(bitmap, 0, 0, width, height);
    const blob = await new Promise<Blob | null>((resolve) =>
      canvas.toBlob(resolve, "image/webp", 0.9),
    );
    return blob ?? file;
  } catch {
    return file;
  }
}
