<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A card's images — one portrait, one logo — stored as bytes.
 *
 * Bytes in the database, like `cms_legal_doc` and `app_user_avatar`, and for the
 * same reason both of those give: a `MEDIUMBLOB` needs no new writable
 * directory on the Plesk host, and host-side setup is this platform's chronic
 * go-live blocker. A file path would work perfectly in development and be one
 * more thing to arrange, per environment, before a single card renders.
 *
 * `(card_id, kind)` is unique because an upload is an upsert: a card has one
 * portrait, and replacing it must not leave the old one behind to be served by
 * a stale reference. `updated_at` is the cache-buster AND the ETag source, so
 * the public route can answer `304` without reading the blob.
 *
 * `width`/`height` are stored because the renderer needs them for the `<img>`
 * dimensions that stop the page from shifting, and reading them back out of the
 * bytes on every render would mean loading the blob to render the markup that
 * points at it.
 */
final class CreateCardsAsset extends AbstractMigration
{
    public function change(): void
    {
        $this->table('card_asset', ['id' => true, 'engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('card_id', 'integer', ['signed' => false])
            ->addColumn('kind', 'string', ['limit' => 20, 'default' => 'portrait'])
            ->addColumn('mime_type', 'string', ['limit' => 60, 'default' => 'image/webp'])
            ->addColumn('size_bytes', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('width', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('height', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('content', 'blob', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::BLOB_MEDIUM])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['card_id', 'kind'], ['unique' => true, 'name' => 'uniq_card_asset'])
            ->addForeignKey('card_id', 'card_page', 'id', ['delete' => 'CASCADE'])
            ->create();
    }
}
