<?php


namespace Peopleaps\Scorm\Model;


use Illuminate\Database\Eloquent\Model;

class ScormScoTrackingModel extends Model
{
    protected $fillable =   [
        'user_id',
        'sco_id',
        'uuid',
        'progression',
        'score_raw',
        'score_min',
        'score_max',
        'score_scaled',
        'lesson_status',
        'completion_status',
        'session_time',
        'total_time_int',
        'total_time_string',
        'entry',
        'suspend_data',
        'credit',
        'exit_mode',
        'lesson_location',
        'lesson_mode',
        'is_locked',
        'details',
        'latest_date',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'details'        => 'array',
    ];

    public function getTable()
    {
        return config('scorm.table_names.scorm_sco_tracking_table', parent::getTable());
    }

    public function sco()
    {
        return $this->belongsTo(ScormScoModel::class, 'sco_id', 'id');
    }
}
