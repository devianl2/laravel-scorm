<?php


namespace Peopleaps\Scorm\Model;


use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $uuid
 * @property string $title
 * @property string $version
 * @property string $entryUrl
 */
class ScormModel extends Model
{

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'id',
        'resource_id',
        'resource_type',
        'title',
        'origin_file',
        'version',
        'ratio',
        'uuid',
        'identifier',
        'entry_url',
        'created_at',
        'updated_at',
    ];

    /**
     * Get the parent resource model (user or post).
     */
    public function resourceable()
    {
        return $this->morphTo(__FUNCTION__, 'resource_type', 'resource_id');
    }

    public function getTable()
    {
        return config('scorm.table_names.scorm_table', parent::getTable());
    }

    public function scos()
    {
        return $this->hasMany(ScormScoModel::class, 'scorm_id', 'id');
    }
}
