<?php
// /api/models/Category.php
require_once __DIR__ . '/BaseModel.php';

class Category extends BaseModel {
    protected $tableName = 'product_categories';
    
    public function __construct($db) {
        parent::__construct($db);
        $this->allowedFilters = ['name', 'description'];
        $this->allowedSorts = ['id', 'name', 'sort_order'];
    }
    
    /**
     * دریافت همه دسته‌بندی‌ها به صورت درختی
     */
    public function getTree() {
        $entity_id = $_SESSION['current_entity_id'];
        $stmt = $this->conn->prepare("
            SELECT c.*, 
                   (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) as product_count
            FROM `{$this->tableName}` c 
            WHERE c.entity_id = ? 
            ORDER BY c.sort_order ASC, c.name ASC
        ");
        $stmt->bind_param("i", $entity_id);
        $stmt->execute();
        $categories = db_stmt_to_assoc_array($stmt);
        
        return $this->buildTree($categories);
    }
    
    /**
     * ساخت ساختار درختی از لیست مسطح
     */
    private function buildTree($items, $parentId = null) {
        $tree = [];
        foreach ($items as $item) {
            if ($item['parent_id'] == $parentId) {
                $children = $this->buildTree($items, $item['id']);
                if ($children) {
                    $item['children'] = $children;
                }
                $tree[] = $item;
            }
        }
        return $tree;
    }
    
    /**
     * دریافت لیست مسطح با نمایش سلسله مراتب
     */
    public function getFlatList() {
        $entity_id = $_SESSION['current_entity_id'];
        $stmt = $this->conn->prepare("
            SELECT c.*, 
                   p.name as parent_name,
                   (SELECT COUNT(*) FROM products pr WHERE pr.category_id = c.id) as product_count
            FROM `{$this->tableName}` c 
            LEFT JOIN `{$this->tableName}` p ON c.parent_id = p.id
            WHERE c.entity_id = ? 
            ORDER BY c.sort_order ASC, c.name ASC
        ");
        $stmt->bind_param("i", $entity_id);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }
    
    /**
     * ذخیره دسته‌بندی
     */
    public function save($data) {
        if (empty(trim($data['name'] ?? ''))) {
            return ['error' => 'نام دسته‌بندی الزامی است.', 'statusCode' => 400];
        }
        
        $entity_id = $_SESSION['current_entity_id'];
        $id = $data['id'] ?? null;
        $name = trim($data['name']);
        $description = trim($data['description'] ?? '');
        $parent_id = !empty($data['parent_id']) ? intval($data['parent_id']) : null;
        $sort_order = intval($data['sort_order'] ?? 0);
        $icon = trim($data['icon'] ?? 'bi-folder');
        $color = trim($data['color'] ?? '#6c757d');
        
        // بررسی تکراری نبودن نام
        $check_id = $id ?: 0;
        $stmt_check = $this->conn->prepare("SELECT id FROM `{$this->tableName}` WHERE name = ? AND entity_id = ? AND id != ?");
        $stmt_check->bind_param("sii", $name, $entity_id, $check_id);
        $stmt_check->execute();
        if ($stmt_check->fetch()) {
            $stmt_check->close();
            return ['error' => 'دسته‌بندی با این نام قبلاً وجود دارد.', 'statusCode' => 409];
        }
        $stmt_check->close();
        
        // جلوگیری از ایجاد حلقه در سلسله مراتب
        if ($id && $parent_id) {
            if ($parent_id == $id) {
                return ['error' => 'یک دسته‌بندی نمی‌تواند والد خودش باشد.', 'statusCode' => 400];
            }
            if ($this->isDescendant($id, $parent_id)) {
                return ['error' => 'نمی‌توان یک دسته‌بندی فرزند را به عنوان والد انتخاب کرد.', 'statusCode' => 400];
            }
        }
        
        try {
            if ($id) {
                $stmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET name = ?, description = ?, parent_id = ?, sort_order = ?, icon = ?, color = ? WHERE id = ? AND entity_id = ?");
                $stmt->bind_param("ssiissii", $name, $description, $parent_id, $sort_order, $icon, $color, $id, $entity_id);
            } else {
                $stmt = $this->conn->prepare("INSERT INTO `{$this->tableName}` (entity_id, name, description, parent_id, sort_order, icon, color) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("issiiss", $entity_id, $name, $description, $parent_id, $sort_order, $icon, $color);
            }
            
            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }
            
            if (!$id) {
                $id = $this->conn->insert_id;
            }
            $stmt->close();
            
            return ['success' => true, 'id' => $id];
        } catch (Exception $e) {
            return ['error' => $e->getMessage(), 'statusCode' => 500];
        }
    }
    
    /**
     * بررسی فرزند بودن
     */
    private function isDescendant($ancestorId, $descendantId) {
        $stmt = $this->conn->prepare("SELECT parent_id FROM `{$this->tableName}` WHERE id = ?");
        $stmt->bind_param("i", $descendantId);
        $stmt->execute();
        $stmt->bind_result($parent_id);
        $stmt->fetch();
        $stmt->close();
        
        if ($parent_id === null) return false;
        if ($parent_id == $ancestorId) return true;
        return $this->isDescendant($ancestorId, $parent_id);
    }
    
    /**
     * حذف دسته‌بندی
     */
    public function delete($id) {
        $entity_id = $_SESSION['current_entity_id'];
        
        // بررسی استفاده در محصولات
        $stmt_check = $this->conn->prepare("SELECT COUNT(*) as cnt FROM products WHERE category_id = ?");
        $stmt_check->bind_param("i", $id);
        $stmt_check->execute();
        $result = db_stmt_to_assoc_array($stmt_check);
        
        if ($result[0]['cnt'] > 0) {
            return ['error' => 'این دسته‌بندی دارای محصول است و قابل حذف نیست.', 'statusCode' => 409];
        }
        
        // بررسی زیردسته‌ها
        $stmt_children = $this->conn->prepare("SELECT COUNT(*) as cnt FROM `{$this->tableName}` WHERE parent_id = ?");
        $stmt_children->bind_param("i", $id);
        $stmt_children->execute();
        $result_children = db_stmt_to_assoc_array($stmt_children);
        
        if ($result_children[0]['cnt'] > 0) {
            return ['error' => 'این دسته‌بندی دارای زیردسته است. ابتدا زیردسته‌ها را حذف یا منتقل کنید.', 'statusCode' => 409];
        }
        
        return parent::delete($id);
    }
    
    /**
     * دریافت مسیر کامل یک دسته‌بندی (breadcrumb)
     */
    public function getPath($categoryId) {
        $path = [];
        $currentId = $categoryId;
        
        while ($currentId) {
            $stmt = $this->conn->prepare("SELECT id, name, parent_id FROM `{$this->tableName}` WHERE id = ?");
            $stmt->bind_param("i", $currentId);
            $stmt->execute();
            $result = db_stmt_to_assoc_array($stmt);
            
            if (empty($result)) break;
            
            array_unshift($path, $result[0]);
            $currentId = $result[0]['parent_id'];
        }
        
        return $path;
    }
}
