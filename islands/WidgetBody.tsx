import { useEffect, useState } from "react";

import { apiFetch } from "@tracht-digital-solutions/tds-shared/api";
import { Skeleton } from "@tracht-digital-solutions/tds-shared/components";

/**
 * The dashboard widget: how many cards exist, and how many are live.
 *
 * Two numbers rather than one, because the interesting state is the gap. "4
 * Karten" says nothing about whether a customer can see theirs.
 *
 * The loading state is a `<Skeleton>` with `aria-busy`, never a literal "…".
 * Twelve of the dashboard's widgets once shipped a static ellipsis that was
 * indistinguishable from a real value and invisible to assistive tech.
 */
export default function WidgetBody() {
  const [summary, setSummary] = useState<{ total: number; published: number } | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let alive = true;
    // `apiFetch` rejects on a network error rather than resolving — an island
    // without a catch here hangs on its skeleton forever.
    apiFetch("/cards/summary")
      .then(async (res) => {
        if (!res.ok) throw new Error(String(res.status));
        return (await res.json()) as { total: number; published: number };
      })
      .then((data) => {
        if (alive) setSummary(data);
      })
      .catch(() => {
        if (alive) setFailed(true);
      });
    return () => {
      alive = false;
    };
  }, []);

  if (failed) {
    return <p className="tds-widget__metric">—</p>;
  }

  return (
    <p className="tds-widget__metric" aria-busy={summary === null}>
      {summary === null ? (
        <Skeleton width="3ch" height="1.75rem" />
      ) : (
        <>
          {summary.published}
          <span className="marginalia"> von {summary.total} veröffentlicht</span>
        </>
      )}
    </p>
  );
}
