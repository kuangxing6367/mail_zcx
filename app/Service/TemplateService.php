<?php

namespace App\Service;

use App\Core\Database;
use RuntimeException;

/**
 * 邮件模板：{{变量}} 渲染，HTML / 纯文本双版本
 */
class TemplateService
{
    private Database $db;
    private string $table;

    public function __construct()
    {
        $this->db    = Database::instance();
        $this->table = $this->db->table('templates');
    }

    public function list(int $page = 1, int $size = 50, string $keyword = ''): array
    {
        $page = max(1, $page);
        $size = min(200, max(1, $size));
        $where  = ['1'];
        $params = [];

        if ($keyword !== '') {
            $where[] = '(code LIKE :kw OR name LIKE :kw)';
            $params['kw'] = '%' . $keyword . '%';
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM `{$this->table}` WHERE {$whereSql}", $params);
        $offset = ($page - 1) * $size;
        $rows = $this->db->fetchAll(
            "SELECT * FROM `{$this->table}` WHERE {$whereSql} ORDER BY id DESC LIMIT {$size} OFFSET {$offset}",
            $params
        );

        return ['total' => $total, 'page' => $page, 'size' => $size, 'items' => $rows];
    }

    public function get(int $id): ?array
    {
        return $this->db->fetch("SELECT * FROM `{$this->table}` WHERE id = ? LIMIT 1", [$id]) ?: null;
    }

    public function getByCode(string $code): ?array
    {
        return $this->db->fetch("SELECT * FROM `{$this->table}` WHERE code = ? LIMIT 1", [$code]) ?: null;
    }

    public function create(array $data): int
    {
        $row = $this->normalize($data, true);
        if ($this->getByCode($row['code'])) {
            throw new RuntimeException('模板标识已存在: ' . $row['code']);
        }
        return $this->db->insert('templates', $row);
    }

    public function update(int $id, array $data): int
    {
        if (!$this->get($id)) {
            throw new RuntimeException('模板不存在: #' . $id);
        }
        $row = $this->normalize($data, false);
        if (isset($row['code'])) {
            $exists = $this->getByCode($row['code']);
            if ($exists && (int) $exists['id'] !== $id) {
                throw new RuntimeException('模板标识已被占用: ' . $row['code']);
            }
        }
        return $row ? $this->db->update('templates', $row, 'id = :wid', ['wid' => $id]) : 0;
    }

    public function delete(int $id): int
    {
        return $this->db->delete('templates', 'id = :id', ['id' => $id]);
    }

    /**
     * 渲染模板
     *
     * @return array{subject:string,html:string,text:string,missing:string[]}
     */
    public function render(string $code, array $vars): array
    {
        $tpl = $this->getByCode($code);
        if (!$tpl) {
            throw new RuntimeException('模板不存在: ' . $code);
        }
        if ((int) $tpl['status'] !== 1) {
            throw new RuntimeException('模板已被禁用: ' . $code);
        }

        $missing = [];
        return [
            'subject' => $this->substitute((string) $tpl['subject'], $vars, $missing),
            'html'    => $this->substitute((string) ($tpl['body_html'] ?? ''), $vars, $missing),
            'text'    => $this->substitute((string) ($tpl['body_text'] ?? ''), $vars, $missing),
            'missing' => array_values(array_unique($missing)),
        ];
    }

    /**
     * 变量替换，支持 {{var}} / {{ var }} / {{a.b}} 点号路径
     * 缺失变量替换为空串并记录
     */
    public function substitute(string $template, array $vars, array &$missing = []): string
    {
        if ($template === '') {
            return '';
        }
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z_][\w\.]*)\s*\}\}/',
            function ($m) use ($vars, &$missing) {
                $key = $m[1];
                $val = $vars;
                foreach (explode('.', $key) as $seg) {
                    if (is_array($val) && array_key_exists($seg, $val)) {
                        $val = $val[$seg];
                    } else {
                        $missing[] = $key;
                        return '';
                    }
                }
                if (is_bool($val)) {
                    return $val ? 'true' : 'false';
                }
                if ($val === null) {
                    return '';
                }
                return is_scalar($val) ? (string) $val : json_encode($val, JSON_UNESCAPED_UNICODE);
            },
            $template
        );
    }

    /** 预览：不落库，直接用传入内容渲染 */
    public function preview(array $data, array $vars): array
    {
        $missing = [];
        return [
            'subject' => $this->substitute((string) ($data['subject'] ?? ''), $vars, $missing),
            'html'    => $this->substitute((string) ($data['body_html'] ?? ''), $vars, $missing),
            'text'    => $this->substitute((string) ($data['body_text'] ?? ''), $vars, $missing),
            'missing' => array_values(array_unique($missing)),
        ];
    }

    private function normalize(array $data, bool $isCreate): array
    {
        $fields = ['code', 'name', 'subject', 'body_html', 'body_text', 'variables', 'status'];
        $row = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $row[$f] = $data[$f];
            }
        }

        if ($isCreate && empty($row['code'])) {
            throw new RuntimeException('缺少必填字段: code');
        }
        if (isset($row['code']) && !preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', (string) $row['code'])) {
            throw new RuntimeException('code 只能包含字母、数字、下划线和短横线');
        }
        if (isset($row['variables']) && is_array($row['variables'])) {
            $row['variables'] = json_encode($row['variables'], JSON_UNESCAPED_UNICODE);
        }
        if (isset($row['status'])) {
            $row['status'] = (int) $row['status'];
        }
        return $row;
    }
}
