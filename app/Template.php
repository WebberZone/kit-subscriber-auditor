<?php

declare(strict_types=1);

namespace KitAudit;

final class Template
{
    public function __construct(private readonly string $viewDirectory)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $view, array $data = []): void
    {
        $viewPath = $this->viewDirectory . '/' . $view . '.php';
        if (!is_file($viewPath)) {
            throw new \RuntimeException('Template not found: ' . $view);
        }
        extract($data, EXTR_SKIP);
        require $viewPath;
    }
}

