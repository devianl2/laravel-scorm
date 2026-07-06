<?php


namespace Peopleaps\Scorm\Model;


use Illuminate\Database\Eloquent\Model;

class ScormScoModel extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'scorm_id',
        'uuid',
        'sco_parent_id',
        'entry_url',
        'identifier',
        'title',
        'visible',
        'sco_parameters',
        'launch_data',
        'max_time_allowed',
        'time_limit_action',
        'block',
        'score_int',
        'score_decimal',
        'completion_threshold',
        'prerequisites',
    ];

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
