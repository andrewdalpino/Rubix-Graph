<?php

namespace Rubix\ML\Graph;

use Rubix\ML\Datasets\Dataset;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Graph\Nodes\Leaf;
use Rubix\ML\Graph\Nodes\Comparison;
use Rubix\ML\Graph\Nodes\BinaryNode;
use InvalidArgumentException;

/**
 * CART
 *
 * Classification and Regression Tree or *CART* is a binary tree that uses
 * comparision (*decision*) nodes at every split in the training data to
 * locate a leaf node.
 *
 * [1] W. Y. Loh. (2011). Classification and Regression Trees.
 *
 * @category    Machine Learning
 * @package     Rubix/ML
 * @author      Andrew DalPino
 */
abstract class CART implements BinaryTree
{
    protected const BETA = 1e-8;

    /**
     * The root node of the tree.
     *
     * @var \Rubix\ML\Graph\Nodes\Comparison|null
     */
    protected $root;

    /**
     * The maximum depth of a branch before it is forced to terminate.
     *
     * @var int
     */
    protected $maxDepth;

    /**
     * The maximum number of samples that a leaf node can contain.
     *
     * @var int
     */
    protected $maxLeafSize;

    /**
     * The minimum increase in purity necessary for a node not to be post pruned.
     *
     * @var float
     */
    protected $minPurityIncrease;

    /**
     * The number of feature columns in the training set.
     *
     * @var int
     */
    protected $featureCount;

    /**
     * @param int $maxDepth
     * @param int $maxLeafSize
     * @param float $minPurityIncrease
     * @throws \InvalidArgumentException
     */
    public function __construct(int $maxDepth = PHP_INT_MAX, int $maxLeafSize = 3, float $minPurityIncrease = 0.)
    {
        if ($maxDepth < 1) {
            throw new InvalidArgumentException('A tree cannot have'
                . ' depth less than 1.');
        }

        if ($maxLeafSize < 1) {
            throw new InvalidArgumentException('At least one sample'
                . ' is required to create a leaf node.');
        }

        if ($minPurityIncrease < 0.) {
            throw new InvalidArgumentException('Min purity increase'
                . ' must be greater than or equal to 0,'
                . " $minPurityIncrease given.");
        }

        $this->maxDepth = $maxDepth;
        $this->maxLeafSize = $maxLeafSize;
        $this->minPurityIncrease = $minPurityIncrease;
    }

    /**
     * Choose the best split for a given dataset.
     *
     * @param \Rubix\ML\Datasets\Labeled $dataset
     * @return \Rubix\ML\Graph\Nodes\Comparison
     */
    abstract protected function findBestSplit(Labeled $dataset) : Comparison;

    /**
     * Terminate the branch.
     *
     * @param \Rubix\ML\Datasets\Labeled $dataset
     * @return \Rubix\ML\Graph\Nodes\BinaryNode
     */
    abstract protected function terminate(Labeled $dataset) : BinaryNode;

    /**
     * Return the root node of the tree.
     *
     * @return \Rubix\ML\Graph\Nodes\Comparison|null
     */
    public function root() : ?Comparison
    {
        return $this->root;
    }

    /**
     * Return the height of the tree.
     *
     * @return int
     */
    public function height() : int
    {
        return isset($this->root) ? $this->root->height() : 0;
    }

    /**
     * Return the balance of the tree.
     *
     * @return int
     */
    public function balance() : int
    {
        return isset($this->root) ? $this->root->balance() : 0;
    }

    /**
     * Is the tree bare?
     *
     * @return bool
     */
    public function bare() : bool
    {
        return !$this->root;
    }

    /**
     * Insert a root node into the tree and recursively split the training data
     * until a terminating condition is met.
     *
     * @param \Rubix\ML\Datasets\Labeled $dataset
     */
    public function grow(Labeled $dataset) : void
    {
        $this->featureCount = $dataset->numColumns();

        $depth = 1;

        $this->root = $this->findBestSplit($dataset);

        $stack = [[$this->root, $depth]];

        while ($stack) {
            [$current, $depth] = array_pop($stack) ?? [];

            [$left, $right] = $current->groups();

            $depth++;

            if ($left->empty() or $right->empty()) {
                $node = $this->terminate($left->append($right));
    
                $current->attachLeft($node);
                $current->attachRight($node);

                continue 1;
            }
    
            if ($depth >= $this->maxDepth) {
                $current->attachLeft($this->terminate($left));
                $current->attachRight($this->terminate($right));
                
                continue 1;
            }

            if ($left->numRows() > $this->maxLeafSize) {
                $node = $this->findBestSplit($left);

                if ($node->purityIncrease() + self::BETA > $this->minPurityIncrease) {
                    $current->attachLeft($node);

                    $stack[] = [$node, $depth];
                } else {
                    $current->attachLeft($this->terminate($left));
                }
            } else {
                $current->attachLeft($this->terminate($left));
            }
    
            if ($right->numRows() > $this->maxLeafSize) {
                $node = $this->findBestSplit($right);
    
                if ($node->purityIncrease() + self::BETA > $this->minPurityIncrease) {
                    $current->attachRight($node);

                    $stack[] = [$node, $depth];
                } else {
                    $current->attachRight($this->terminate($right));
                }
            } else {
                $current->attachRight($this->terminate($right));
            }

            $current->cleanup();
        }
    }

    /**
     * Search the tree for a leaf node.
     *
     * @param array $sample
     * @return \Rubix\ML\Graph\Nodes\BinaryNode|null
     */
    public function search(array $sample) : ?BinaryNode
    {
        $current = $this->root;

        while ($current) {
            if ($current instanceof Comparison) {
                $value = $current->value();

                if (is_string($value)) {
                    if ($sample[$current->column()] === $value) {
                        $current = $current->left();
                    } else {
                        $current = $current->right();
                    }
                } else {
                    if ($sample[$current->column()] < $value) {
                        $current = $current->left();
                    } else {
                        $current = $current->right();
                    }
                }

                continue 1;
            }

            if ($current instanceof Leaf) {
                break 1;
            }
        }

        return $current;
    }

    /**
     * Return an array indexed by feature column that contains the normalized
     * importance score of that feature.
     *
     * @return array
     */
    public function featureImportances() : array
    {
        if (!$this->root) {
            return [];
        }

        $importances = array_fill(0, $this->featureCount, 0.);

        foreach ($this->dump() as $node) {
            if ($node instanceof Comparison) {
                $index = $node->column();

                $importances[$index] += $node->purityIncrease();
            }
        }

        $total = array_sum($importances) ?: self::BETA;

        foreach ($importances as &$importance) {
            $importance /= $total;
        }

        arsort($importances);

        return $importances;
    }

    /**
     * Return an array of all the nodes in the tree starting at a
     * given node.
     *
     * @return array
     */
    public function dump() : array
    {
        $nodes = [];

        $stack = [$this->root];

        while ($stack) {
            $nodes[] = $current = array_pop($stack);

            if ($current instanceof BinaryNode) {
                foreach ($current->children() as $child) {
                    $stack[] = $child;
                }
            }
        }

        return $nodes;
    }
}
