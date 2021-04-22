<?php


namespace Peopleaps\Scorm\Model;


use Illuminate\Database\Eloquent\Model;

class ScormModel extends Model
{
    public function getTable()
    {
        return config('scorm.table_names.scorm_table', parent::getTable());
    }

    public function scos() {
        return $this->hasMany(ScormScoModel::class, 'scorm_id', 'id');
    }
}
