<?php

namespace App\Repositories;

use App\Models\Curriculum;

class CurriculumRepository
{
   public function __construct(private Curriculum $model) {}

   public function find(int|string $identifier, array $with = [], array $select = ['*']): ?ClassLevelArm
   {
      return $this->model
            ->select($select)
            ->with($with)
            ->where($this->getField($identifier), $this->getValue($identifier))
            ->first();
   }

   public function findByUuid(string $uuid, array $with = [], array $select = ['*'], array $where = [])
   {
      return $this->model
            ->select($select)
            ->with($with)
            ->where('uuid', $uuid)
            ->first();
   }

   private function getField(int|string $identifier): string
   {
      return ctype_digit((string) $identifier) ? 'id' : 'uuid';
   }

   private function getValue(int|string $identifier): int|string
   {
      return ctype_digit((string) $identifier) ? (int) $identifier : $identifier;
   }

   public function all(array $with = [], array $select = [], array $where = [])
   {
      return $this->model
            ->when(!empty($select), fn($q) => $q->select($select))
            ->with($with)
            ->where($where)
            ->get();
   }
}