<?php
declare(strict_types=1);

namespace GH\Core\Selection;

enum SelectionMode: string
{
    case Ids = 'ids';        // explicit id list (granular checkbox selection)
    case Filter = 'filter';  // condition set (re-runs the filter at execute time)
    case All = 'all';        // every item the source can produce
}
