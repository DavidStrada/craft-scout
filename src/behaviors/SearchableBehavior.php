<?php

namespace rias\scout\behaviors;

use Craft;
use craft\base\Element;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\MatrixBlock;
use craft\elements\Tag;
use craft\elements\User;
use craft\helpers\ElementHelper;
use League\Fractal\Manager;
use League\Fractal\Resource\Item;
use League\Fractal\Serializer\ArraySerializer;
use rias\scout\engines\Engine;
use rias\scout\jobs\MakeSearchable;
use rias\scout\Scout;
use rias\scout\ScoutIndex;
use Tightenco\Collect\Support\Collection;
use yii\base\Behavior;

/**
 * @mixin Element
 *
 * @property Element $owner
 * @property int $id
 */
class SearchableBehavior extends Behavior
{
    public function validatesCriteria(ScoutIndex $scoutIndex): bool
    {
        return $scoutIndex->criteria
            ->id($this->owner->id)
            ->exists();
    }

    public function getIndices(): Collection
    {
        return Scout::$plugin
            ->getSettings()
            ->getIndices()
            ->filter(function (ScoutIndex $scoutIndex) {
                return $scoutIndex->elementType === get_class($this->owner)
                    && (int) $scoutIndex->criteria->siteId === (int) $this->owner->siteId;
            });
    }

    public function searchableUsing(): Collection
    {
        return $this->getIndices()->map(function (ScoutIndex $scoutIndex) {
            return Scout::$plugin->getSettings()->getEngine($scoutIndex);
        });
    }

    public function searchable(bool $propagate = true)
    {
        if (!$this->shouldBeSearchable()) {
            return;
        }

        $this->searchableUsing()->each(function (Engine $engine) {
            if (!$this->validatesCriteria($engine->scoutIndex)) {
                return $engine->delete($this->owner);
            }

            if (Scout::$plugin->getSettings()->queue) {
                return Craft::$app->getQueue()->push(
                    new MakeSearchable([
                        'id'        => $this->owner->id,
                        'siteId'    => $this->owner->siteId,
                        'indexName' => $engine->scoutIndex->indexName,
                    ])
                );
            }

            return $engine->update($this->owner);
        });

        if ($propagate) {
            $this->getRelatedElements()->each(function (Element $relatedElement) {
                /* @var SearchableBehavior $relatedElement */
                $relatedElement->searchable(false);
            });
        }
    }

    public function unsearchable()
    {
        if (!Scout::$plugin->getSettings()->sync) {
            return;
        }

        $this->searchableUsing()->each->delete($this->owner);
    }

    public function toSearchableArray(ScoutIndex $scoutIndex): array
    {
        return (new Manager())
            ->setSerializer(new ArraySerializer())
            ->createData(new Item($this->owner, $scoutIndex->getTransformer()))
            ->toArray();
    }

    public function getRelatedElements(): Collection
    {
        $assets = Asset::find()->relatedTo($this->owner)->site('*')->all();
        $categories = Category::find()->relatedTo($this->owner)->site('*')->all();
        $entries = Entry::find()->relatedTo($this->owner)->site('*')->all();
        $tags = Tag::find()->relatedTo($this->owner)->site('*')->all();
        $users = User::find()->relatedTo($this->owner)->site('*')->all();
        $globalSets = GlobalSet::find()->relatedTo($this->owner)->site('*')->all();
        $matrixBlocks = MatrixBlock::find()->relatedTo($this->owner)->site('*')->all();

        $products = [];
        $variants = [];
        // @codeCoverageIgnoreStart
        if (class_exists(Product::class)) {
            $products = Product::find()->relatedTo($this->owner)->site('*')->all();
            $variants = Variant::find()->relatedTo($this->owner)->site('*')->all();
        }
        // @codeCoverageIgnoreEnd

        return collect(array_merge(
            $assets,
            $categories,
            $entries,
            $tags,
            $users,
            $globalSets,
            $matrixBlocks,
            $products,
            $variants
        ));
    }

    public function shouldBeSearchable(): bool
    {
        if (!Scout::$plugin->getSettings()->sync) {
            return false;
        }

        if ($this->owner->propagating) {
            return false;
        }

        if (ElementHelper::isDraftOrRevision($this->owner)) {
            return false;
        }

        return true;
    }
}