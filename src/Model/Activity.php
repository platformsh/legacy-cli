<?php
namespace CommerceGuys\Platform\Cli\Model;

class Activity extends HalResource
{

    public function getDescription()
    {
        $data = $this->getProperties();
        switch ($data['type']) {
            case 'environment.activate':
                return sprintf(
                  "%s activated environment %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['environment']['title']
                );

            case 'environment.backup':
                return sprintf(
                  "%s created backup of %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['environment']['title']
                );

            case 'environment.branch':
                return sprintf(
                  "%s branched %s from %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['outcome']['title'],
                  $data['payload']['parent']['title']
                );

            case 'environment.delete':
                return sprintf(
                  "%s deleted environment %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['environment']['title']
                );

            case 'environment.deactivate':
                return sprintf(
                  "%s deactivated environment %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['environment']['title']
                );

            case 'environment.initialize':
                return sprintf(
                  "%s initialized environment %s with profile %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['outcome']['title'],
                  $data['payload']['profile']
                );

            case 'environment.merge':
                return sprintf(
                  "%s merged %s into %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['outcome']['title'],
                  $data['payload']['environment']['title']
                );

            case 'environment.push':
                return sprintf(
                  "%s pushed to %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['environment']['title']
                );

            case 'environment.restore':
                return sprintf(
                  "%s restored %s to %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['environment'],
                  substr($data['payload']['commit'], 0, 7)
                );

            case 'environment.synchronize':
                $syncedCode = !empty($data['payload']['synchronize_code']);
                if ($syncedCode && !empty($data['payload']['synchronize_data'])) {
                    $syncType = 'code and data';
                } elseif ($syncedCode) {
                    $syncType = 'code';
                } else {
                    $syncType = 'data';
                }
                return sprintf(
                  "%s synced %s's %s with %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['outcome']['title'],
                  $syncType,
                  $data['payload']['environment']['title']
                );
        }
        return $data['type'];
    }

}
