import { useState } from "react";

import { apiFetch } from "@tracht-digital-solutions/tds-shared/api";
import { ConfirmDialog, Spinner, toast } from "@tracht-digital-solutions/tds-shared/components";
import { invalidate, staleClass, useCachedJson } from "@tracht-digital-solutions/tds-shared/data";

/**
 * Einstellungen → Visitenkarten: the one connection to the card site.
 *
 * There is exactly one, and that is the point worth saying on screen: a single
 * app answers every customer domain, so a new customer needs a DNS record and an
 * alias in the hosting — not a pairing, not a key, not a deployment.
 */

interface Connection {
  version?: string;
  profile?: string;
  origin?: string;
  siteKey?: string;
  connectedAt?: string;
  resource?: { type: string; id: string };
}

interface Pairing {
  pairingId?: string;
  code?: string;
  connectUrl?: string;
  expiresAt?: string;
}

export default function CardsConnection() {
  const query = useCachedJson<{ connection: Connection } | { error: string }>("/cards/connection");
  const [origin, setOrigin] = useState("https://karte.tracht-digital.de");
  const [pairing, setPairing] = useState<Pairing | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [confirmDisconnect, setConfirmDisconnect] = useState(false);

  const payload = query.data;
  const connection = payload && "connection" in payload ? payload.connection : null;
  const stale = query.stale && payload !== undefined;

  const pair = async (): Promise<void> => {
    setBusy(true);
    setError(null);
    setPairing(null);
    try {
      const res = await apiFetch("/cards/connection/pairing", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ origin }),
      });
      const body = (await res.json().catch(() => ({}))) as Pairing & { error?: string };
      if (!res.ok) {
        // 422 here almost always means `http://` behind Plesk. Saying so beats
        // repeating the server's word for it.
        setError(body.error ?? `Kopplung fehlgeschlagen (${res.status}).`);
        return;
      }
      setPairing(body);
      invalidate("/cards/connection");
      toast.success("Kopplung angelegt. Die Daten unten sind nur jetzt sichtbar.");
    } catch (err) {
      toast.danger(`Kopplung fehlgeschlagen: ${err instanceof Error ? err.message : String(err)}`);
    } finally {
      setBusy(false);
    }
  };

  const disconnect = async (): Promise<void> => {
    setConfirmDisconnect(false);
    setBusy(true);
    try {
      const res = await apiFetch("/cards/connection", { method: "DELETE" });
      if (!res.ok) {
        setError(`Trennen fehlgeschlagen (${res.status}).`);
        return;
      }
      invalidate("/cards/connection");
      toast.success("Verbindung getrennt. Die Kartenseite liest nichts mehr.");
    } catch (err) {
      toast.danger(`Trennen fehlgeschlagen: ${err instanceof Error ? err.message : String(err)}`);
    } finally {
      setBusy(false);
    }
  };

  if (query.loading) {
    return (
      <p aria-busy="true">
        <Spinner /> Verbindung wird geprüft …
      </p>
    );
  }

  return (
    <div className={staleClass(stale, "tds-stack")} aria-busy={stale}>
      {error ? (
        <p className="tds-alert tds-alert--danger" role="alert">
          {error}
        </p>
      ) : null}

      {connection ? (
        <div className="tds-stack">
          <p>
            Verbunden mit <code>{connection.origin}</code>
            {connection.connectedAt ? ` seit ${connection.connectedAt}` : ""}.
          </p>
          <p className="marginalia">
            Eine Verbindung genügt für alle Karten. Eine neue Kundendomain braucht einen
            DNS-Eintrag und einen Alias im Hosting, keine zweite Kopplung.
          </p>
          <button
            type="button"
            className="btn btn-ghost"
            disabled={busy}
            onClick={() => setConfirmDisconnect(true)}
          >
            Verbindung trennen
          </button>
        </div>
      ) : (
        <form
          className="tds-stack"
          onSubmit={(e) => {
            e.preventDefault();
            void pair();
          }}
        >
          <p>Die Kartenseite ist noch nicht verbunden. Bis dahin liest sie nichts.</p>
          <label className="tds-stack">
            <span>Origin der Kartenseite</span>
            <input
              className="field-boxed"
              type="url"
              value={origin}
              required
              onChange={(e) => setOrigin(e.target.value)}
            />
            <span className="marginalia">
              Die Rückfall-Domain, nicht eine Kundendomain. Hinter Plesk muss sie mit
              <code>https://</code> beginnen.
            </span>
          </label>
          <button type="submit" className="btn btn-primary" disabled={busy}>
            {busy ? "Wird gekoppelt …" : "Kopplung anlegen"}
          </button>
        </form>
      )}

      {pairing ? (
        <div className="tds-alert tds-alert--info">
          <p>
            Diese Daten einmalig auf <code>{origin}/install</code> eintragen. Sie werden nicht
            wieder angezeigt.
          </p>
          <dl>
            <dt>Code</dt>
            <dd>
              <code>{pairing.code}</code>
            </dd>
            {pairing.connectUrl ? (
              <>
                <dt>Adresse</dt>
                <dd>
                  <code>{pairing.connectUrl}</code>
                </dd>
              </>
            ) : null}
            {pairing.expiresAt ? (
              <>
                <dt>Gültig bis</dt>
                <dd>{pairing.expiresAt}</dd>
              </>
            ) : null}
          </dl>
        </div>
      ) : null}

      <ConfirmDialog
        open={confirmDisconnect}
        title="Verbindung trennen?"
        message="Die Kartenseite liest danach nichts mehr und liefert nur noch, was in ihrem Cache liegt."
        confirmLabel="Trennen"
        onConfirm={() => void disconnect()}
        onCancel={() => setConfirmDisconnect(false)}
      />
    </div>
  );
}
