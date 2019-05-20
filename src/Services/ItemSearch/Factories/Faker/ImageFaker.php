<?php

namespace IO\Services\ItemSearch\Factories\Faker;

class ImageFaker extends AbstractFaker
{
    public function fill($data)
    {
        $itemImages = $this->makeImageList();
        $variationImages = $this->makeImageList();

        $default = [
            "all"       => array_merge($itemImages, $variationImages),
            "item"      => $itemImages,
            "variation" => $variationImages
        ];

        $this->merge($data, $default);
        return $data;
    }

    public function makeImageList()
    {
        $imageList = [];
        $imageCount = rand(1, 3);

        $itemId = $this->global("itemId", $this->number());
        for($i = 0; $i < $imageCount; $i++)
        {
            $imageId        = $this->number();
            $width          = rand(200, 500);
            $height         = rand(200, 500);
            $imageName      = $this->trans("IO::Faker.itemImageName");
            $url            = $this->image($width, $height, $imageName);
            $imageList[]    = [
                "id"                            => $imageId,
                "itemId"                        => $itemId,
                "type"                          => $this->rand(['internal', 'external']),
                "fileType"                      => $this->rand(['jpg', 'jpeg', 'gif', 'png', 'svg']),
                "path"                          => $url,
                "position"                      => $this->number(),
                "updatedAt"                     => $this->dateString(),
                "createdAt"                     => $this->dateString(),
                "md5Checksum"                   => $this->hash(),
                "width"                         => $width,
                "height"                        => $height,
                "size"                          => $width * $height,
                "storageProviderId"             => 0,
                "cleanImageName"                => $imageName,
                "url"                           => $url,
                "urlMiddle"                     => $url,
                "urlPreview"                    => $url,
                "urlSecondPreview"              => $url,
                "documentUploadPath"            => $url,
                "documentUploadPathPreview"     => $url,
                "documentUploadPreviewWidth"    => $width,
                "documentUploadPreviewHeight"   => $height,
                "availabilities"                => [
                    "mandant" => $this->number(1, 10),
                    "listing" => $this->number(1, 10),
                    "market"  => $this->number(1, 10)
                ],
                "names"                         => [
                    [
                        "imageId"   => $imageId,
                        "lang"      => $this->lang,
                        "name"      => $imageName,
                        "alternate" => $this->text(0, 5)
                    ]
                ]
            ];
        }

        return $imageList;
    }
}