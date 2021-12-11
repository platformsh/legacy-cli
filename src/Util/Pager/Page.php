<?php

namespace Platformsh\Cli\Util\Pager;

final class Page
{
    /** @var array */
    public $items = [];
    /** @var int */
    public $total = 0;
    /** @var int */
    public $pageNumber = 1;
    /** @var int */
    public $pageCount = 1;
    /** @var int */
    public $itemsPerPage = 0;

    /**
     * Displays the page count and other info.
     *
     * @return string
     */
    public function displayInfo()
    {
        return \sprintf('page <info>%d</info> of <info>%d</info>; <info>%d</info> per page, <info>%d</info> total',
            $this->pageNumber, $this->pageCount, $this->itemsPerPage, $this->total);
    }
}
