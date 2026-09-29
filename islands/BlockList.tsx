import { useId, type ReactNode } from "react";

import {
  CARD_BLOCK_CATALOG,
  type CardBlock,
  type CardBlockType,
} from "@tracht-digital-solutions/tds-shared/schemas";

/**
 * The free blocks of a card, as an ordered list you can add to and reorder.
 *
 * ### Why this is deliberately plain
 *
 * It is the platform's first block editor, and the temptation is a canvas with
 * drag handles. Two reasons not to, here: `setPointerCapture` on a draggable row
 * swallows the click that follows it, which is how a list becomes unusable while
 * looking fine; and a card has at most a handful of blocks, so up/down buttons
 * are both reachable by keyboard and enough. A richer editor can come once
 * somebody is actually short of a feature.
 *
 * ### The rule the fields follow
 *
 * Every change SPREADS, never replaces: `onChange({ ...block, text })`. A form
 * that rebuilds the object from the inputs it renders silently drops the keys it
 * does not — which is how a partial schema blanks live content. The same rule
 * the website-CMS's structured form carries, for the same reason.
 *
 * ### No CSS ships from this package
 *
 * Layout is `tds-row`, `tds-stack`, `tds-list__row`, `tds-toolbar`, `btn`,
 * `chip` and `field-boxed` from tds-shared. `npm run lint:primitives` is a regex
 * scan over this file.
 */

export interface BlockListProps {
  blocks: CardBlock[];
  onChange: (blocks: CardBlock[]) => void;
  disabled?: boolean;
}

/** A label per type, so a collapsed row says what it is. */
const TYPE_LABEL: Record<CardBlockType, string> = {
  links: "Linkgruppe",
  heading: "Überschrift",
  text: "Text",
  hours: "Öffnungszeiten",
  socials: "Profile",
  divider: "Trennlinie",
};

const ICONS = ["link", "phone", "mail", "map", "calendar", "download", "shop", "chat"] as const;

const NETWORKS = [
  "linkedin",
  "xing",
  "instagram",
  "facebook",
  "youtube",
  "github",
  "whatsapp",
  "website",
] as const;

export default function BlockList({ blocks, onChange, disabled = false }: BlockListProps) {
  const listId = useId();

  const replace = (index: number, block: CardBlock): void => {
    onChange(blocks.map((b, i) => (i === index ? block : b)));
  };

  const move = (index: number, by: number): void => {
    const target = index + by;
    if (target < 0 || target >= blocks.length) return;
    const next = [...blocks];
    const moved = next[index]!;
    next[index] = next[target]!;
    next[target] = moved;
    onChange(next);
  };

  const remove = (index: number): void => {
    onChange(blocks.filter((_, i) => i !== index));
  };

  const add = (type: CardBlockType): void => {
    const entry = CARD_BLOCK_CATALOG.find((c) => c.id === type);
    if (!entry) return;
    // Cloned, not shared: the catalog entry is a template and two blocks added
    // from it must not end up being the same object.
    onChange([...blocks, structuredClone(entry.block)]);
  };

  return (
    <div className="tds-stack">
      <ul className="tds-list" aria-label="Blöcke der Karte" id={listId}>
        {blocks.map((block, index) => (
          <li key={`${block.type}-${index}`} className="tds-list__row">
            <div className="tds-stack">
              <div className="tds-row">
                <span className="chip chip--neutral">{TYPE_LABEL[block.type]}</span>
                <span className="marginalia">
                  {index + 1} von {blocks.length}
                </span>
                <button
                  type="button"
                  className="btn btn-ghost"
                  disabled={disabled || index === 0}
                  onClick={() => move(index, -1)}
                  aria-label={`${TYPE_LABEL[block.type]} nach oben`}
                >
                  ↑
                </button>
                <button
                  type="button"
                  className="btn btn-ghost"
                  disabled={disabled || index === blocks.length - 1}
                  onClick={() => move(index, 1)}
                  aria-label={`${TYPE_LABEL[block.type]} nach unten`}
                >
                  ↓
                </button>
                <button
                  type="button"
                  className="btn btn-ghost"
                  disabled={disabled}
                  onClick={() => remove(index)}
                  aria-label={`${TYPE_LABEL[block.type]} entfernen`}
                >
                  Entfernen
                </button>
              </div>
              <BlockFields
                block={block}
                disabled={disabled}
                onChange={(next) => replace(index, next)}
              />
            </div>
          </li>
        ))}
      </ul>

      {blocks.length === 0 ? (
        <p className="marginalia">Noch keine Blöcke. Eine Karte braucht mindestens eine Linkgruppe.</p>
      ) : null}

      <div className="tds-toolbar" role="group" aria-label="Block hinzufügen">
        {CARD_BLOCK_CATALOG.map((entry) => (
          <button
            key={entry.id}
            type="button"
            className="btn btn-ghost"
            disabled={disabled}
            title={entry.hint}
            onClick={() => add(entry.id)}
          >
            + {entry.label}
          </button>
        ))}
      </div>
    </div>
  );
}

interface FieldsProps {
  block: CardBlock;
  disabled: boolean;
  onChange: (block: CardBlock) => void;
}

function BlockFields({ block, disabled, onChange }: FieldsProps) {
  switch (block.type) {
    case "divider":
      return <p className="marginalia">Nur eine Linie. Nichts einzustellen.</p>;

    case "heading":
    case "text":
      return (
        <label className="tds-stack">
          <span>{block.type === "heading" ? "Überschrift" : "Text"}</span>
          {block.type === "heading" ? (
            <input
              className="field-boxed"
              type="text"
              value={block.text}
              disabled={disabled}
              maxLength={160}
              onChange={(e) => onChange({ ...block, text: e.target.value })}
            />
          ) : (
            <textarea
              className="field-boxed"
              rows={3}
              value={block.text}
              disabled={disabled}
              maxLength={2000}
              onChange={(e) => onChange({ ...block, text: e.target.value })}
            />
          )}
        </label>
      );

    case "links":
      return (
        <div className="tds-stack">
          <label className="tds-stack">
            <span>Überschrift der Gruppe (optional)</span>
            <input
              className="field-boxed"
              type="text"
              value={block.label ?? ""}
              disabled={disabled}
              maxLength={120}
              onChange={(e) => onChange({ ...block, label: e.target.value || null })}
            />
          </label>
          <RowEditor
            label="Links"
            rows={block.items}
            disabled={disabled}
            addLabel="Link hinzufügen"
            empty={{ label: "", href: "", note: null, icon: "link" as const }}
            onChange={(items) => onChange({ ...block, items })}
            render={(item, update) => (
              <>
                <input
                  className="field-boxed"
                  type="text"
                  placeholder="Beschriftung"
                  aria-label="Beschriftung"
                  value={item.label}
                  disabled={disabled}
                  maxLength={120}
                  onChange={(e) => update({ ...item, label: e.target.value })}
                />
                <input
                  className="field-boxed"
                  type="text"
                  placeholder="https://, mailto: oder tel:"
                  aria-label="Ziel"
                  value={item.href}
                  disabled={disabled}
                  maxLength={600}
                  onChange={(e) => update({ ...item, href: e.target.value })}
                />
                <input
                  className="field-boxed"
                  type="text"
                  placeholder="Zusatz (optional)"
                  aria-label="Zusatz"
                  value={item.note ?? ""}
                  disabled={disabled}
                  maxLength={160}
                  onChange={(e) => update({ ...item, note: e.target.value || null })}
                />
                <select
                  className="field-boxed"
                  aria-label="Symbol"
                  value={item.icon ?? "link"}
                  disabled={disabled}
                  onChange={(e) => update({ ...item, icon: e.target.value as (typeof ICONS)[number] })}
                >
                  {ICONS.map((icon) => (
                    <option key={icon} value={icon}>
                      {icon}
                    </option>
                  ))}
                </select>
              </>
            )}
          />
        </div>
      );

    case "socials":
      return (
        <RowEditor
          label="Profile"
          rows={block.items}
          disabled={disabled}
          addLabel="Profil hinzufügen"
          empty={{ network: "linkedin" as const, href: "" }}
          onChange={(items) => onChange({ ...block, items })}
          render={(item, update) => (
            <>
              <select
                className="field-boxed"
                aria-label="Netzwerk"
                value={item.network}
                disabled={disabled}
                onChange={(e) => update({ ...item, network: e.target.value as (typeof NETWORKS)[number] })}
              >
                {NETWORKS.map((network) => (
                  <option key={network} value={network}>
                    {network}
                  </option>
                ))}
              </select>
              <input
                className="field-boxed"
                type="text"
                placeholder="https://"
                aria-label="Ziel"
                value={item.href}
                disabled={disabled}
                maxLength={600}
                onChange={(e) => update({ ...item, href: e.target.value })}
              />
            </>
          )}
        />
      );

    case "hours":
      return (
        <div className="tds-stack">
          <label className="tds-stack">
            <span>Überschrift (optional)</span>
            <input
              className="field-boxed"
              type="text"
              value={block.label ?? ""}
              disabled={disabled}
              maxLength={120}
              onChange={(e) => onChange({ ...block, label: e.target.value || null })}
            />
          </label>
          <RowEditor
            label="Zeiten"
            rows={block.rows}
            disabled={disabled}
            addLabel="Zeile hinzufügen"
            empty={{ days: "", time: "" }}
            onChange={(rows) => onChange({ ...block, rows })}
            render={(row, update) => (
              <>
                <input
                  className="field-boxed"
                  type="text"
                  placeholder="Mo–Do"
                  aria-label="Tage"
                  value={row.days}
                  disabled={disabled}
                  maxLength={60}
                  onChange={(e) => update({ ...row, days: e.target.value })}
                />
                <input
                  className="field-boxed"
                  type="text"
                  placeholder="08:00–17:00"
                  aria-label="Zeit"
                  value={row.time}
                  disabled={disabled}
                  maxLength={60}
                  onChange={(e) => update({ ...row, time: e.target.value })}
                />
              </>
            )}
          />
        </div>
      );
  }
}

interface RowEditorProps<T> {
  label: string;
  rows: readonly T[];
  disabled: boolean;
  addLabel: string;
  empty: T;
  onChange: (rows: T[]) => void;
  render: (row: T, update: (next: T) => void) => ReactNode;
}

/**
 * The repeating rows inside a block.
 *
 * One component for links, profiles and opening hours: they differ only in the
 * fields a row carries, and three near-identical copies is how the reorder
 * buttons end up behaving differently in each.
 */
function RowEditor<T>({
  label,
  rows,
  disabled,
  addLabel,
  empty,
  onChange,
  render,
}: RowEditorProps<T>) {
  const update = (index: number, next: T): void => {
    onChange(rows.map((r, i) => (i === index ? next : r)));
  };

  const move = (index: number, by: number): void => {
    const target = index + by;
    if (target < 0 || target >= rows.length) return;
    const next = [...rows];
    const moved = next[index]!;
    next[index] = next[target]!;
    next[target] = moved;
    onChange(next);
  };

  return (
    <div className="tds-stack">
      <ul className="tds-list" aria-label={label}>
        {rows.map((row, index) => (
          <li key={index} className="tds-list__row">
            <div className="tds-row">
              {render(row, (next) => update(index, next))}
              <button
                type="button"
                className="btn btn-ghost"
                disabled={disabled || index === 0}
                onClick={() => move(index, -1)}
                aria-label={`Zeile ${index + 1} nach oben`}
              >
                ↑
              </button>
              <button
                type="button"
                className="btn btn-ghost"
                disabled={disabled || index === rows.length - 1}
                onClick={() => move(index, 1)}
                aria-label={`Zeile ${index + 1} nach unten`}
              >
                ↓
              </button>
              <button
                type="button"
                className="btn btn-ghost"
                disabled={disabled}
                onClick={() => onChange(rows.filter((_, i) => i !== index))}
                aria-label={`Zeile ${index + 1} entfernen`}
              >
                Entfernen
              </button>
            </div>
          </li>
        ))}
      </ul>
      <button
        type="button"
        className="btn btn-ghost"
        disabled={disabled}
        onClick={() => onChange([...rows, structuredClone(empty)])}
      >
        + {addLabel}
      </button>
    </div>
  );
}
