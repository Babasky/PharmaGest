<?php

namespace App\Util;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * Page de résultats paginée côté serveur.
 *
 * @template T
 *
 * @implements \IteratorAggregate<int, T>
 */
final class Page implements \IteratorAggregate, \Countable
{
    public const PAR_PAGE = 25;

    /**
     * @param list<T> $elements
     */
    private function __construct(
        public readonly array $elements,
        public readonly int $page,
        public readonly int $parPage,
        public readonly int $total,
    ) {
    }

    /**
     * @return self<mixed>
     */
    public static function depuis(QueryBuilder $qb, int $page, int $parPage = self::PAR_PAGE): self
    {
        $page = max(1, $page);
        $qb->setFirstResult(($page - 1) * $parPage)->setMaxResults($parPage);
        $paginator = new Paginator($qb, fetchJoinCollection: false);

        return new self(array_values(iterator_to_array($paginator)), $page, $parPage, \count($paginator));
    }

    public function nombreDePages(): int
    {
        return max(1, (int) ceil($this->total / $this->parPage));
    }

    public function aPrecedente(): bool
    {
        return $this->page > 1;
    }

    public function aSuivante(): bool
    {
        return $this->page < $this->nombreDePages();
    }

    /**
     * @return \ArrayIterator<int, T>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->elements);
    }

    public function count(): int
    {
        return \count($this->elements);
    }
}
