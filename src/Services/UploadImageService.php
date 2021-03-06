<?php

namespace Novius\Backpack\CRUD\Services;

use Illuminate\Database\Eloquent\Model;

/**
 * Class UploadImageService
 * @package Novius\Backpack\CRUD\Services
 */
class UploadImageService extends AbstractUploadService
{
    /**
     * Filled with images during model saving
     * Images will be used on Model "saved" event
     *
     * @var array
     */
    protected $tmpImages = [];

    /**
     * Set Model images attributes with good values
     *
     * @param Model $model
     * @return bool
     */
    public function fillImages(Model $model)
    {
        $this->initModel($model);
        foreach ($this->filesAttributes($this->model->uploadableImages()) as $imageAttribute) {
            $this->setUploadedImage($imageAttribute);
        }

        return true;
    }

    /**
     * Save images on disk and update Model columns with images path
     *
     * @param Model $model
     * @return bool
     */
    public function saveImages(Model $model)
    {
        $this->initModel($model);

        if (empty($this->tmpImages)) {
            return true;
        }

        foreach ($this->tmpImages as $imageAttributeName => $image) {
            // 1. Get image path
            $filePath = $this->getImagePath($imageAttributeName);

            // 2. Store the image on disk.
            \Storage::disk(self::STORAGE_DISK_NAME)->put($filePath, $image->stream());

            // 3. Save the path to the database
            $this->model->fillUploadedImageAttributeValue($imageAttributeName, $filePath);

            if (isset($this->tmpImages[$imageAttributeName])) {
                unset($this->tmpImages[$imageAttributeName]);
            }
        }

        return $this->model->save();
    }

    /**
     * Generate image path
     *
     * @param $imageAttributeName
     * @return string
     */
    protected function getImagePath(string $imageAttributeName): string
    {
        $folderName = snake_case(class_basename(get_class($this->model)));
        $destination_path = $folderName.'/'.$this->model->getKey().'/'.$imageAttributeName;
        $imageSlugAttribute = array_get($this->slugAttributes($this->model->uploadableImages()), $imageAttributeName);

        // 1. Generate a filename.
        $filename = md5(time()).'.jpg';
        if (!empty($imageSlugAttribute)) {
            $filename = str_slug($this->model->{$imageSlugAttribute}).'.jpg';
        }

        return $destination_path.'/'.$filename;
    }

    /**
     * Delete images on disk
     *
     * @param Model $model
     * @return bool
     */
    public function deleteImages(Model $model)
    {
        $this->initModel($model);
        foreach ($this->filesAttributes($this->model->uploadableImages()) as $imageAttribute) {
            \Storage::disk(self::STORAGE_DISK_NAME)->delete($this->model->{$imageAttribute});
        }

        return true;
    }

    /**
     * Fill Model image attribute with good value
     *
     * @param string $imageAttributeName
     */
    protected function setUploadedImage(string $imageAttributeName)
    {
        $value = $this->model->{$imageAttributeName};

        if (empty($value)) {
            // Delete old image
            if (!empty($this->model->getOriginal($imageAttributeName))) {
                \Storage::disk(self::STORAGE_DISK_NAME)->delete($this->model->getOriginal($imageAttributeName));
            }
            $this->model->fillUploadedImageAttributeValue($imageAttributeName, '');

            return;
        }

        if (starts_with($value, 'data:image')) {
            // Upload a new image
            $this->tmpImages[$imageAttributeName] = \Image::make($value);
            if (empty($this->model->getOriginal($imageAttributeName))) {
                // No image before
                $this->model->fillUploadedImageAttributeValue($imageAttributeName, '');
            } else {
                // Erase existing image
                $this->model->fillUploadedImageAttributeValue($imageAttributeName, $this->model->getOriginal($imageAttributeName));
            }

            return;
        }

        if (ends_with($value, '.jpg') && !empty($this->model->getOriginal($imageAttributeName))) {
            // Keep same image
            $this->model->fillUploadedImageAttributeValue($imageAttributeName, $this->model->getOriginal($imageAttributeName));

            return;
        }

        if (!ends_with($value, '.jpg')) {
            // No image uploaded
            $this->model->fillUploadedImageAttributeValue($imageAttributeName, '');
        }
    }
}
