import { SummaryMetric } from "@tracht-digital-solutions/tds-shared/components";

/** "Visitenkarten" widget body — published cards out of all cards. */
export default function WidgetBody() {
  return (
    <SummaryMetric<{ total: number; published: number }>
      endpoint="/cards/summary"
      value={(d) => (
        <>
          {d.published}
          <span className="marginalia"> von {d.total} veröffentlicht</span>
        </>
      )}
    />
  );
}
