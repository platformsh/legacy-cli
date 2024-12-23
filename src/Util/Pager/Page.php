<?php

declare(strict_types=1);

namespace Platformsh\Cli\Util\Pager;

final class Page
{
    /** @var object[] */
    public array $items = [];
    public int $total = 0;
    public int $pageNumber = 1;
    public int $pageCount = 1;
    public int $itemsPerPage = 0;

    /**
     * Displays the page count and other info.
     *
     * @return string
     */
    public function displayInfo(): string
    {
        return \sprintf(
            'page <info>%d</info> of <info>%d</info>; <info>%d</info> per page, <info>%d</info> total',
            $this->pageNumber,
            $this->pageCount,
            $this->itemsPerPage,
            $this->total,
        );
    }
}
