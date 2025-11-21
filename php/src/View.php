<?php

declare(strict_types=1);

namespace Attendly;

final class View
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
    }

    /**
     * Render a PHP template under views/ with scoped variables.
     */
    public function render(string $template, array $data = []): string
    {
        $templatePath = $this->basePath . '/' . ltrim($template, '/');
        if (!str_ends_with($templatePath, '.php')) {
            $templatePath .= '.php';
        }
        if (!is_file($templatePath)) {
            throw new \RuntimeException("Template not found: {$templatePath}");
        }

        $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        extract($data, EXTR_SKIP);
        $e = $escape;

        ob_start();
        /** @noinspection PhpIncludeInspection */
        include $templatePath;
        return (string)ob_get_clean();
    }

    /**
     * Render a template into a layout (views/layout.php by default).
     */
    public function renderWithLayout(string $template, array $data = [], string $layout = 'layout'): string
    {
        $content = $this->render($template, $data);
        $layoutPath = $this->basePath . '/' . ltrim($layout, '/');
        if (!str_ends_with($layoutPath, '.php')) {
            $layoutPath .= '.php';
        }
        if (!is_file($layoutPath)) {
            throw new \RuntimeException("Layout not found: {$layoutPath}");
        }

        $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        extract($data, EXTR_SKIP);
        $e = $escape;

        ob_start();
        /** @noinspection PhpIncludeInspection */
        include $layoutPath;
        return (string)ob_get_clean();
    }
}
