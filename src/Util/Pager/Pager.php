<?php

namespace Platformsh\Cli\Util\Pager;

class Pager
{
    /**
     * Creates a Page.
     *
     * @param array $items
     * @param int $pageNumber
     * @param int $itemsPerPage
     *
     * @return Page
     */
    public function page(array $items, $pageNumber, $itemsPerPage): Page
    {
        if ($pageNumber < 1) {
            throw new \InvalidArgumentException('The page number cannot be less than 1');
        }
        $page = new Page();
        $page->total = \count($items);
        if ($itemsPerPage < 1) {
            $page->pageCount = 1;
            $page->items = $items;
            $page->pageNumber = 1;
            $page->itemsPerPage = 0;
        } else {
            $page->pageCount = (int) \ceil(\count($items) / $itemsPerPage);
            $page->items = \array_slice($items, ($pageNumber - 1) * $itemsPerPage, $itemsPerPage);
            $page->pageNumber = $pageNumber;
            $page->itemsPerPage = $itemsPerPage;
        }
        return $page;
    }
}
