<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class DatabaseUpgradeService
{
    public function __construct(private PDO $db) {}

    public function upgrade(): void
    {
        if (!$this->tableExists('blog_settings')) return;
        if (!$this->columnExists('blog_settings', 'content_mode')) {
            $this->db->exec("ALTER TABLE `blog_settings` ADD COLUMN `content_mode` ENUM('woocommerce','server_folder') NOT NULL DEFAULT 'woocommerce' AFTER `featured_media_id`");
        }
        if (!$this->columnExists('blog_settings', 'folder_source_slug')) {
            $this->db->exec("ALTER TABLE `blog_settings` ADD COLUMN `folder_source_slug` VARCHAR(64) NULL AFTER `content_mode`");
        }
        if (!$this->columnExists('blog_settings', 'ai_provider')) {
            $this->db->exec("ALTER TABLE `blog_settings` ADD COLUMN `ai_provider` ENUM('openai','anthropic','qwen') NOT NULL DEFAULT 'openai' AFTER `publish_mode`");
        }
        $columns = [
            'blog_branding_enabled' => "TINYINT(1) NOT NULL DEFAULT 0",
            'blog_logo_path' => "VARCHAR(500) NULL",
            'blog_logo_size_pct' => "TINYINT UNSIGNED NOT NULL DEFAULT 12",
            'blog_logo_position' => "VARCHAR(20) NOT NULL DEFAULT 'top-right'",
            'blog_brand_text' => "VARCHAR(300) NULL",
            'blog_text_color' => "CHAR(7) NOT NULL DEFAULT '#FFFFFF'",
            'blog_text_background_enabled' => "TINYINT(1) NOT NULL DEFAULT 1",
            'blog_text_background_color' => "CHAR(7) NOT NULL DEFAULT '#050F19'",
            'blog_text_background_opacity' => "TINYINT UNSIGNED NOT NULL DEFAULT 35",
            'blog_text_position' => "VARCHAR(20) NOT NULL DEFAULT 'bottom-center'",
            'blog_text_opacity' => "TINYINT UNSIGNED NOT NULL DEFAULT 90",
            'blog_text_size_pct' => "TINYINT UNSIGNED NOT NULL DEFAULT 3",
        ];
        foreach($columns as $name=>$definition){
            if(!$this->columnExists('blog_settings',$name))$this->db->exec("ALTER TABLE `blog_settings` ADD COLUMN `{$name}` {$definition}");
        }
    }

    private function tableExists(string $table): bool
    {
        $query = $this->db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $query->execute([$table]);
        return (int)$query->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $query = $this->db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $query->execute([$table, $column]);
        return (int)$query->fetchColumn() > 0;
    }
}
