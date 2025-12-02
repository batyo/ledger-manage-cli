<?php

namespace App\Service;

use App\Repository\RepositoryInterface;
use App\Entity\CategoryEntry;
use App\Validation\Validator;

class CategoryManager
{
    private RepositoryInterface $repo;

    public function __construct(RepositoryInterface $repo)
    {
        $this->repo = $repo;
    }


    /**
     * カテゴリ名とカテゴリタイプの妥当性を検証する
     *
     * @param mixed $name カテゴリ名
     * @param mixed $categoryType カテゴリタイプ
     * @throws \InvalidArgumentException
     */
    public function validateCategory(mixed $name, mixed $categoryType): void
    {
        if (!Validator::validateCategoryName($name)) {
            throw new \InvalidArgumentException('Invalid category name.');
        }

        $entry = $this->repo->fetchCategoryByName($name);
        if ($entry !== null) {
            throw new \InvalidArgumentException('Category name already exists.');
        }

        if (!Validator::validateCategoryType($categoryType)) {
            throw new \InvalidArgumentException('Invalid category type.');
        }
    }


    /**
     * カテゴリ更新時の妥当性検証を行う
     *
     * @param int $id カテゴリID
     * @param mixed $name カテゴリ名
     * @param mixed $categoryType カテゴリタイプ
     * @throws \InvalidArgumentException
     */
    public function validateCategoryForUpdate(int $id, mixed $name, mixed $categoryType): void
    {
        $currentEntry = $this->repo->fetchCategoryById($id);
        if ($currentEntry === null) {
            throw new \InvalidArgumentException('Category not found.');
        }

        if (!Validator::validateCategoryName($name)) {
            throw new \InvalidArgumentException('Invalid category name.');
        }

        $fetchEntry = $this->repo->fetchCategoryByName($name);
        $isSameName = $currentEntry->name === $name;

        // 名前が変更されていて、かつ既に存在する名前の場合はエラー
        if ($fetchEntry !== null && !$isSameName) {
            throw new \InvalidArgumentException('Category name already exists.');
        }

        if (!Validator::validateCategoryType($categoryType)) {
            throw new \InvalidArgumentException('Invalid category type.');
        }
    }


    /**
     * 新しいカテゴリを登録する
     * 
     * @param CategoryEntry $entry 追加するカテゴリ
     */
    public function registerCategory(CategoryEntry $entry): void
    {
        $this->repo->insertCategory($entry);
    }


    /**
     * カテゴリー名を更新する
     *
     * @param integer $id ID
     * @param string $newName 新しいカテゴリー名
     */
    public function updateCategoryName(int $id, string $newName): void
    {
        $entry = $this->repo->fetchCategoryById($id);
        if ($entry === null) {
            throw new \InvalidArgumentException('Category not found.');
        }

        $newEntry = $entry->changeCategoryName($newName);
        $this->repo->updateCategory($newEntry);
    }


    /**
     * 複数カラムをまとめて更新する
     *
     * @param int $id
     * @param string|null $newName
     * @param int|null $newCategoryType
     * @throws \InvalidArgumentException
     */
    public function updateCategoryFields(int $id, ?string $newName = null, ?int $newCategoryType = null): void
    {
        $entry = $this->repo->fetchCategoryById($id);
        if ($entry === null) {
            throw new \InvalidArgumentException('Category not found.');
        }

        $name = $newName ?? $entry->name;
        $type = $newCategoryType ?? $entry->categoryType;

        $newEntry = new \App\Entity\CategoryEntry($id, $name, $type);
        $this->repo->updateCategory($newEntry);
    }


    /**
     * 安全にカテゴリを削除する
     *
     * - 既存の取引がある場合、--reassign で再割当先カテゴリを指定することを要求する。
     * - 再割当が指定された場合、対象カテゴリの取引をすべて再割当してからカテゴリを削除する。
     *
     * @param int $id 削除対象カテゴリID
     * @param int|null $reassignTo 取引を移す先のカテゴリID（未指定なら null）
     * @param bool $force 取引が無い場合のみ強制削除を許可（取引があるときの強制削除は不可）
     * @throws \InvalidArgumentException
     */
    public function deleteCategory(int $id, ?int $reassignTo = null, bool $force = false): void
    {
        if (!Validator::validateCategoryId($id)) {
            throw new \InvalidArgumentException('This is an invalid ID.');
        }

        $entry = $this->repo->fetchCategoryById($id);
        if ($entry === null) {
            throw new \InvalidArgumentException('Category not found.');
        }

        // そのカテゴリを参照する取引を取得
        $transactions = $this->repo->fetchTransactions(['categoryId' => $id]);

        if (!empty($transactions)) {
            // 取引がある場合
            if ($reassignTo === null) {
                throw new \InvalidArgumentException(
                    'This category is currently in use for transactions. To delete it, specify the reassignment destination using --reassign [ID] [reassignID].'
                );
            }

            if (!Validator::validateCategoryId($reassignTo)) {
                throw new \InvalidArgumentException('This is an invalid reassignment ID.');
            }
            if ($reassignTo === $id) {
                throw new \InvalidArgumentException('Reassignment to the same category is not allowed.');
            }

            $target = $this->repo->fetchCategoryById($reassignTo);
            if ($target === null) {
                throw new \InvalidArgumentException('Reassignment category not found.');
            }

            // 取引のカテゴリを再割当して保存
            foreach ($transactions as $tx) {
                /** @var TransactionEntry $tx */
                $updated = $tx->changeCategory($reassignTo);
                $this->repo->updateTransaction($updated);
            }

            // 再割当後に削除
            $this->repo->deleteCategory($entry);
            return;
        }

        // 取引がない場合は削除を行う（force フラグは任意）
        $this->repo->deleteCategory($entry);
    }


    /**
     * すべてのカテゴリを取得する
     * @return CategoryEntry[] すべてのカテゴリの配列
     */
    public function findCategories(): array
    {
        return $this->repo->fetchAllCategories();
    }


    /**
     * 指定IDのカテゴリを取得する
     *
     * @param int $id カテゴリID
     * @return CategoryEntry|null カテゴリエントリ、または存在しない場合は null
     */
    public function findCategoryById(int $id): ?CategoryEntry
    {
        return $this->repo->fetchCategoryById($id);
    }


    /**
     * カテゴリIDをキー、カテゴリ名を値とする連想配列を取得する
     *
     * @return array<int, string> カテゴリIDをキー、カテゴリ名を値とする連想配列
     */
    public function getCategoryMap(): array
    {
        $categoryMap = [];
        $categories = $this->findCategories();
        foreach ($categories as $c) {
            $categoryMap[$c->id] = $c->name;
        }
        return $categoryMap;
    }
}
