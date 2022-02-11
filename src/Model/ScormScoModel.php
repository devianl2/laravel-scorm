<?php


namespace Peopleaps\Scorm\Model;


use Illuminate\Database\Eloquent\Model;

class ScormScoModel extends Model
{
    public function getTable()
    {
        return config('scorm.table_names.scorm_sco_table', parent::getTable());
    }

    public function scorm()
    {
        return $this->belongsTo(ScormModel::class, 'scorm_id', 'id');
    }

    public function scoTrackings()
    {
        return $this->hasMany(ScormScoTrackingModel::class, 'sco_id', 'id');
    }

    public function children()
    {
        return $this->hasMany(ScormScoModel::class, 'sco_parent_id', 'id');
    }
}
