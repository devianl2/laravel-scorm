<?php


namespace Peopleaps\Scorm\Model;


use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $uuid
 * @property string $title
 * @property string $version
 * @property string $entryUrl
 * @property array|null $metadata
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
        'metadata',
        'created_at',
        'updated_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'metadata' => 'array',
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

    /**
     * Get a specific metadata value
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getMetadata($key, $default = null)
    {
        $metadata = $this->metadata ?? [];
        return $metadata[$key] ?? $default;
    }

    /**
     * Set a specific metadata value
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function setMetadata($key, $value)
    {
        $metadata = $this->metadata ?? [];
        $metadata[$key] = $value;
        $this->metadata = $metadata;
    }

    /**
     * Get package creation date from manifest
     *
     * @return string|null
     */
    public function getPackageCreationDate()
    {
        return $this->getMetadata('created_at');
    }

    /**
     * Get package size
     *
     * @return int|null
     */
    public function getPackageSize()
    {
        return $this->getMetadata('package_size');
    }

    /**
     * Get package creator from manifest
     *
     * @return string|null
     */
    public function getPackageCreator()
    {
        return $this->getMetadata('created_by');
    }

    /**
     * Get all metadata as array
     *
     * @return array
     */
    public function getAllMetadata()
    {
        return $this->metadata ?? [];
    }

    /**
     * Check if metadata has a specific key
     *
     * @param string $key
     * @return bool
     */
    public function hasMetadata($key)
    {
        $metadata = $this->metadata ?? [];
        return array_key_exists($key, $metadata);
    }
}
