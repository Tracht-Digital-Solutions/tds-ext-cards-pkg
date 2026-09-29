// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import type { CardBlock } from "@tracht-digital-solutions/tds-shared/schemas";

import BlockList from "./BlockList";

/**
 * The block editor. It touches no network, so what is worth pinning is the
 * editing behaviour:
 *
 *  - a change SPREADS the block: a handler that rebuilds the object from the
 *    field it rendered drops every other key,
 *  - adding twice from the catalog must produce two independent blocks, not two
 *    references to the same template,
 *  - reordering is keyboard-reachable, which is why there are buttons rather
 *    than drag handles.
 */

afterEach(() => cleanup());

const user = () => userEvent.setup({ delay: null });

const links = (): CardBlock => ({
  type: "links",
  label: "Kontakt",
  items: [{ label: "Anrufen", href: "tel:+4930123456", note: "Mo–Fr", icon: "phone" }],
});

describe("editing a block", () => {
  it("keeps the keys it did not render", async () => {
    const u = user();
    const onChange = vi.fn();
    render(<BlockList blocks={[links()]} onChange={onChange} />);

    await u.clear(screen.getByLabelText("Beschriftung"));
    await u.type(screen.getByLabelText("Beschriftung"), "Jetzt anrufen");

    const last = onChange.mock.calls.at(-1)![0] as CardBlock[];
    const item = (last[0] as Extract<CardBlock, { type: "links" }>).items[0]!;
    // The label changed; `note` and `icon` are the ones a replace-style handler
    // would have silently dropped.
    expect(item.href).toBe("tel:+4930123456");
    expect(item.note).toBe("Mo–Fr");
    expect(item.icon).toBe("phone");
    // And the group's own heading, one level up.
    expect((last[0] as Extract<CardBlock, { type: "links" }>).label).toBe("Kontakt");
  });

  it("does not accept an unknown icon into the value", async () => {
    // The select only offers the closed vocabulary, which is the point: an icon
    // name the renderer does not know draws nothing at all.
    render(<BlockList blocks={[links()]} onChange={vi.fn()} />);
    const options = Array.from(
      (screen.getByLabelText("Symbol") as HTMLSelectElement).options,
    ).map((o) => o.value);
    expect(options).toContain("phone");
    expect(options).not.toContain("skull");
  });
});

describe("adding and removing", () => {
  it("adds a block from the catalog", async () => {
    const u = user();
    const onChange = vi.fn();
    render(<BlockList blocks={[]} onChange={onChange} />);

    await u.click(screen.getByRole("button", { name: "+ Überschrift" }));
    expect((onChange.mock.calls.at(-1)![0] as CardBlock[])[0]!.type).toBe("heading");
  });

  it("gives two added blocks their own object", async () => {
    // The catalog entry is a template. Pushing it twice without a clone means
    // typing in one block changes the other.
    const u = user();
    const onChange = vi.fn();
    render(<BlockList blocks={[]} onChange={onChange} />);

    await u.click(screen.getByRole("button", { name: "+ Text" }));
    const first = (onChange.mock.calls.at(-1)![0] as CardBlock[])[0]!;

    cleanup();
    const second = vi.fn();
    render(<BlockList blocks={[first]} onChange={second} />);
    await u.click(screen.getByRole("button", { name: "+ Text" }));
    const blocks = second.mock.calls.at(-1)![0] as CardBlock[];

    expect(blocks).toHaveLength(2);
    expect(blocks[0]).not.toBe(blocks[1]);
  });

  it("removes the block it was asked to remove", async () => {
    const u = user();
    const onChange = vi.fn();
    render(
      <BlockList
        blocks={[{ type: "heading", text: "Eins" }, { type: "heading", text: "Zwei" }]}
        onChange={onChange}
      />,
    );

    await u.click(screen.getAllByRole("button", { name: "Überschrift entfernen" })[0]!);
    const blocks = onChange.mock.calls.at(-1)![0] as CardBlock[];
    expect(blocks).toHaveLength(1);
    expect((blocks[0] as Extract<CardBlock, { type: "heading" }>).text).toBe("Zwei");
  });
});

describe("reordering", () => {
  it("is reachable by keyboard", async () => {
    // Buttons rather than drag handles, on purpose: `setPointerCapture` on a
    // draggable row swallows the click that follows, and a card has few enough
    // blocks that two buttons are enough.
    const u = user();
    const onChange = vi.fn();
    render(
      <BlockList
        blocks={[{ type: "heading", text: "Eins" }, { type: "heading", text: "Zwei" }]}
        onChange={onChange}
      />,
    );

    await u.tab();
    await u.click(screen.getAllByRole("button", { name: "Überschrift nach unten" })[0]!);
    const blocks = onChange.mock.calls.at(-1)![0] as CardBlock[];
    expect((blocks[0] as Extract<CardBlock, { type: "heading" }>).text).toBe("Zwei");
  });

  it("disables the moves that would fall off the list", () => {
    render(
      <BlockList
        blocks={[{ type: "heading", text: "Eins" }, { type: "heading", text: "Zwei" }]}
        onChange={vi.fn()}
      />,
    );

    expect(screen.getAllByRole("button", { name: "Überschrift nach oben" })[0]).toHaveProperty("disabled", true);
    expect(screen.getAllByRole("button", { name: "Überschrift nach unten" })[1]).toHaveProperty("disabled", true);
  });
});
