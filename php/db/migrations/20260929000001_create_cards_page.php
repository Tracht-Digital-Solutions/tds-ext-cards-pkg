<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * One business-card page: the fixed fields, plus the free blocks as JSON.
 *
 * ### Why the fields are split this way
 *
 * A card exports a vCard and a JSON-LD `Person`, and both want their values BY
 * NAME — `TEL`, `EMAIL`, `ADR` are not interchangeable and cannot be recovered
 * from a free-form block. So the identity of the card is columns. Everything an
 * owner wants to add beyond that is a block, validated by `cardBlocks` in
 * tds-shared and stored here as one JSON string. Denormalised on purpose, the
 * same call the website-CMS makes for `cms_block`: the validator owns the shape.
 *
 * ### `domain` and `slug`
 *
 * Both are addresses, and the reason there are two is that a customer's DNS is
 * not on our schedule. `slug` works from the moment the row exists
 * (`karte.tracht-digital.de/<slug>`); `domain` is filled once the alias and the
 * certificate are in place. `domain` is UNIQUE because it is what the public app
 * looks a card up by — a second row with the same domain would make which card
 * a visitor gets depend on row order.
 *
 * Stored lowercased, without `www.` and without a port. The normalisation is in
 * `Support\CardDomain`, and the frontend's `resolveHost` mirrors it exactly: if
 * "stored" and "looked up" disagree by one dot, the card 404s on its own domain
 * and nothing anywhere is red.
 *
 * ### `company_id` has NO foreign key, deliberately
 *
 * It points at the customers module's company. A real FK across two extensions
 * would tie this module's migration to that one's having run first — and they
 * share one `phinxlog` and one ordering, so the coupling is real and would abort
 * the whole run rather than just this table. An index gives the lookups; the
 * module tolerates a dangling id, which is what a deleted company leaves.
 */
final class CreateCardsPage extends AbstractMigration
{
    public function change(): void
    {
        $this->table('card_page', ['id' => true, 'engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('slug', 'string', ['limit' => 80])
            ->addColumn('domain', 'string', ['limit' => 190, 'null' => true])
            // Unsigned: production is MySQL 8, which rejects a signedness
            // mismatch that MariaDB silently corrects — green locally, fatal on
            // the host. No FK; see the docblock.
            ->addColumn('company_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('lang', 'string', ['limit' => 2, 'default' => 'de'])
            // --- the fixed fields -----------------------------------------
            ->addColumn('display_name', 'string', ['limit' => 160, 'default' => ''])
            ->addColumn('role', 'string', ['limit' => 160, 'null' => true])
            ->addColumn('company_name', 'string', ['limit' => 160, 'null' => true])
            ->addColumn('tagline', 'string', ['limit' => 240, 'null' => true])
            ->addColumn('phone', 'string', ['limit' => 60, 'null' => true])
            ->addColumn('mobile', 'string', ['limit' => 60, 'null' => true])
            ->addColumn('email', 'string', ['limit' => 190, 'null' => true])
            ->addColumn('website', 'string', ['limit' => 300, 'null' => true])
            ->addColumn('address_line', 'string', ['limit' => 190, 'null' => true])
            ->addColumn('postal_code', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('city', 'string', ['limit' => 120, 'null' => true])
            ->addColumn('country', 'string', ['limit' => 2, 'null' => true])
            // --- appearance ------------------------------------------------
            ->addColumn('accent', 'string', ['limit' => 9, 'default' => '#1f3a5f'])
            ->addColumn('surface', 'string', ['limit' => 20, 'default' => 'paper'])
            ->addColumn('theme', 'string', ['limit' => 10, 'default' => 'light'])
            // --- the rest --------------------------------------------------
            ->addColumn('meta_description', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('blocks', 'text', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_MEDIUM, 'null' => true])
            // Draft by default. A row that goes live the moment it is created is
            // a half-filled card on a customer's domain.
            ->addColumn('draft', 'boolean', ['default' => true])
            ->addColumn('published_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['slug'], ['unique' => true, 'name' => 'uniq_card_page_slug'])
            // Unique over a nullable column: MySQL permits any number of NULLs
            // in a unique index, so every card still waiting for its DNS
            // coexists here — while two cards claiming one domain cannot.
            ->addIndex(['domain'], ['unique' => true, 'name' => 'uniq_card_page_domain'])
            ->addIndex(['company_id'], ['name' => 'idx_card_page_company'])
            ->create();
    }
}
