<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         4.1.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Database\Expression;

use Cake\Database\ExpressionInterface;
use Cake\Database\ValueBinder;
use Closure;
use InvalidArgumentException;

/**
 * This represents a SQL window expression used by aggregate and window functions.
 */
class WindowExpression implements ExpressionInterface, WindowInterface
{
    /**
     * @var \Cake\Database\ExpressionInterface[]
     */
    protected $partitions = [];

    /**
     * @var \Cake\Database\Expression\OrderByExpression|null
     */
    protected $order;

    /**
     * @var array
     */
    protected $frame;

    /**
     * @var string
     */
    protected $exclusion;

    /**
     * @inheritDoc
     */
    public function partition($partitions)
    {
        if (!$partitions) {
            return $this;
        }

        if (!is_array($partitions)) {
            $partitions = [$partitions];
        }

        foreach ($partitions as &$partition) {
            if (is_string($partition)) {
                $partition = new IdentifierExpression($partition);
            }
        }

        /** @psalm-suppress InvalidPropertyAssignmentValue */
        $this->partitions = array_merge($this->partitions, $partitions);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function order($fields)
    {
        if (!$fields) {
            return $this;
        }

        if ($this->order === null) {
            $this->order = new OrderByExpression();
        }

        $this->order->add($fields);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function range(?int $start, ?int $end = 0)
    {
        if (func_num_args() === 1) {
            return $this->frame(self::RANGE, $start, self::PRECEDING);
        } else {
            return $this->frame(self::RANGE, $start, self::PRECEDING, $end, self::FOLLOWING);
        }
    }

    /**
     * @inheritDoc
     */
    public function rows(?int $start, ?int $end = 0)
    {
        if (func_num_args() === 1) {
            return $this->frame(self::ROWS, $start, self::PRECEDING);
        } else {
            return $this->frame(self::ROWS, $start, self::PRECEDING, $end, self::FOLLOWING);
        }
    }

    /**
     * @inheritDoc
     */
    public function groups(?int $start, ?int $end = 0)
    {
        if (func_num_args() === 1) {
            return $this->frame(self::GROUPS, $start, self::PRECEDING);
        } else {
            return $this->frame(self::GROUPS, $start, self::PRECEDING, $end, self::FOLLOWING);
        }
    }

    /**
     * @inheritDoc
     */
    public function frame(
        int $type,
        $startOffset,
        int $startDirection,
        $endOffset = null,
        int $endDirection = self::FOLLOWING
    ) {
        $validTypes = [self::RANGE, self::ROWS, self::GROUPS];
        if (!in_array($type, $validTypes)) {
            throw new InvalidArgumentException('Frame type must be RANGE, ROWS or GROUP.');
        }

        $validDirections = [self::PRECEDING, self::FOLLOWING];
        if (
            !in_array($startDirection, $validDirections) ||
            !in_array($endDirection, $validDirections)
        ) {
            throw new InvalidArgumentException('Frame directions must be PRECEDING or FOLLOWING.');
        }

        if (
            (is_int($startOffset) && $startOffset < 0) ||
            (is_int($endOffset) && $endOffset < 0)
        ) {
            throw new InvalidArgumentException('Frame offsets must be non-negative.');
        }

        if (
            in_array($type, [self::ROWS, self::GROUPS]) &&
            (
                ($startOffset !== null && !is_int($startOffset)) ||
                ($endOffset !== null && !is_int($endOffset))
            )
        ) {
            throw new InvalidArgumentException('Frame offsets for ROWS and GROUPS must be null or integers.');
        }

        if (
            $type === self::RANGE &&
            (
                ($startOffset !== null && !is_int($startOffset) && !is_string($startOffset)) ||
                ($endOffset !== null && !is_int($endOffset) && !is_string($endOffset))
            )
        ) {
            throw new InvalidArgumentException('Frame offsets for RANGE must be null, integers or interval strings.');
        }

        $this->frame = [
            'type' => $type,
            'start' => [
                'offset' => $startOffset,
                'direction' => $startDirection,
            ],
        ];

        if (func_num_args() > 3) {
            $this->frame['end'] = [
                'offset' => $endOffset,
                'direction' => $endDirection,
            ];
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function excludeCurrent()
    {
        $this->exclusion = 'CURRENT ROW';

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function excludeGroup()
    {
        $this->exclusion = 'GROUP';

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function excludeTies()
    {
        $this->exclusion = 'TIES';

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function excludeNoOthers()
    {
        $this->exclusion = 'NO OTHERS';

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function sql(ValueBinder $generator): string
    {
        $partitionSql = '';
        if ($this->partitions) {
            $expressions = [];
            foreach ($this->partitions as $partition) {
                $expressions[] = $partition->sql($generator);
            }

            $partitionSql = 'PARTITION BY ' . implode(', ', $expressions);
        }

        $orderSql = $this->order ? $orderSql = $this->order->sql($generator) : '';

        $frameSql = '';
        if ($this->frame) {
            $types = ['RANGE', 'ROWS', 'GROUPS'];

            $offset = $this->buildOffsetSql(
                $generator,
                $this->frame['start']['offset'],
                $this->frame['start']['direction']
            );

            $frameSql = sprintf(
                '%s %s%s',
                $types[$this->frame['type']],
                isset($this->frame['end']) ? 'BETWEEN ' : '',
                $offset
            );

            if (isset($this->frame['end'])) {
                $offset = $this->buildOffsetSql(
                    $generator,
                    $this->frame['end']['offset'],
                    $this->frame['end']['direction']
                );

                $frameSql .= ' AND ' . $offset;
            }

            if ($this->exclusion !== null) {
                $frameSql .= ' EXCLUDE ' . $this->exclusion;
            }
        }

        return sprintf(
            'OVER (%s%s%s%s%s)',
            $partitionSql,
            $partitionSql && $orderSql ? ' ' : '',
            $orderSql,
            ($partitionSql || $orderSql) && $frameSql ? ' ' : '',
            $frameSql
        );
    }

    /**
     * @inheritDoc
     */
    public function traverse(Closure $visitor)
    {
        foreach ($this->partitions as $partition) {
            $visitor($partition);
            $partition->traverse($visitor);
        }

        if ($this->order) {
            $visitor($this->order);
            $this->order->traverse($visitor);
        }

        return $this;
    }

    /**
     * Builds frame offset sql.
     *
     * @param \Cake\Database\ValueBinder $generator Value binder
     * @param string|int|null $offset Frame offset
     * @param int $direction Frame offset direction
     * @return string
     */
    protected function buildOffsetSql(ValueBinder $generator, $offset, int $direction): string
    {
        if ($offset === 0) {
            return 'CURRENT ROW';
        }

        if (is_string($offset)) {
            $placeholder = $generator->placeholder('param');
            $generator->bind($placeholder, $offset, 'string');
            $offset = $placeholder;
        }

        $directions = ['PRECEDING', 'FOLLOWING'];
        $sql = sprintf(
            '%s %s',
            $offset ?? 'UNBOUNDED',
            $directions[$direction]
        );

        return $sql;
    }
}
