<?php
/**
 * 뷰 렌더러. 레이아웃(사이드바+톱바) 안에 콘텐츠 템플릿을 끼운다.
 */
class View
{
    /**
     * @param string $tpl    'customers/index' → app/views/customers/index.php
     * @param array  $data   뷰에 노출할 변수
     * @param string $layout 'default' | 'blank' | 'auth'
     */
    public static function render(string $tpl, array $data = [], string $layout = 'default'): void
    {
        $content = self::capture($tpl, $data);
        if ($layout === 'none') {
            echo $content;
            return;
        }
        $layoutFile = VIEW_PATH . '/layout/' . $layout . '.php';
        if (!is_file($layoutFile)) {
            echo $content;
            return;
        }
        $data['__content'] = $content;
        $data['__title'] = $data['title'] ?? ($GLOBALS['config']['APP_NAME'] ?? 'EDEN CRM');
        extract($data, EXTR_SKIP);
        $__content = $content;
        require $layoutFile;
    }

    /** 부분 템플릿을 문자열로 렌더. */
    public static function capture(string $tpl, array $data = []): string
    {
        $file = VIEW_PATH . '/' . $tpl . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("View not found: $tpl");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return ob_get_clean();
    }

    /** 부분 템플릿 즉시 출력(레이아웃/뷰 내부에서 사용). */
    public static function partial(string $tpl, array $data = []): void
    {
        echo self::capture($tpl, $data);
    }

    /** 오류 페이지. */
    public static function renderError(int $code, string $title, string $message): void
    {
        $data = ['code' => $code, 'title' => $title, 'message' => $message];
        $layout = Auth::check() ? 'default' : 'blank';
        try {
            self::render('errors/error', $data, $layout);
        } catch (\Throwable $e) {
            echo "<h1>{$code} {$title}</h1><p>" . Util::e($message) . "</p>";
        }
    }
}
