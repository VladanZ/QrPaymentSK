<?php

namespace rikudou\SkQrPayment;

use DateTime;
use DateTimeInterface;
use Endroid\QrCode\QrCode;
use Rikudou\Iban\Iban\IbanInterface;
use rikudou\SkQrPayment\Exception\InvalidTypeException;
use rikudou\SkQrPayment\Exception\QrPaymentException;
use rikudou\SkQrPayment\Iban\IbanBicPair;

final class QrPayment
{
    /**
     * @var IbanInterface[]
     */
    private $ibans = [];

    /**
     * @var int
     */
    private $variableSymbol;

    /**
     * @var int
     */
    private $specificSymbol;

    /**
     * @var int
     */
    private $constantSymbol;

    /**
     * @var string
     */
    private $currency = 'EUR';

    /**
     * @var string
     */
    private $comment = '';

    /**
     * @var string
     */
    private $internalId = '';

    /**
     * @var DateTimeInterface|null
     */
    private $dueDate = null;

    /**
     * @var float
     */
    private $amount;

    /**
     * @var string
     */
    private $country = 'SK';

    /**
     * @var string|null
     */
    private $xzPath = null;

    /**
     * @var string
     */
    private $payeeName = '';

    /**
     * QrPayment constructor.
     *
     * @param IbanInterface ...$ibans
     */
    public function __construct(IbanInterface ...$ibans)
    {
        $this->setIbans($ibans);
    }

    /**
     * Specifies options in array in format:
     * property_name => value
     *
     * @param array $options
     *
     * @return $this
     */
    public function setOptions(array $options): self
    {
        foreach ($options as $key => $value) {
            $method = sprintf('set%s', ucfirst($key));
            if (method_exists($this, $method)) {
                /** @var callable $callable */
                $callable = [$this, $method];
                call_user_func($callable, $value);
            }
        }

        return $this;
    }

    /**
     * @throws QrPaymentException
     *
     * @return string
     */
    public function getQrString(): string
    {
        if (!count($this->ibans)) {
            throw new QrPaymentException('Cannot generate QR payment with no IBANs');
        }

        $ibans = $this->getNormalizedIbans();

        $dataArray = [
            0 => $this->internalId, // payment identifier (can be anything)
            1 => '1', // count of payments
            2 => [
                true, // regular payment
                round($this->amount, 2),
                $this->currency,
                $this->getDueDate()->format('Ymd'),
                $this->variableSymbol,
                $this->constantSymbol,
                $this->specificSymbol,
                '', // variable symbol, constant symbol and specific symbol in SEPA format (empty because the 3 previous are already defined)
                $this->comment,
                count($this->ibans), // count of target accounts
                // continues below in foreach
            ],
        ];

        foreach ($ibans as $iban) { // each of the ibans is appended, then the bic
            $dataArray[2][] = $iban->getIban()->asString();
            $dataArray[2][] = $iban->getBic();
        }

        $dataArray[2][] = 0; // standing order
        $dataArray[2][] = 0; // direct debit
        $dataArray[2][] = $this->payeeName;
        $dataArray[2][] = ''; // payee's address line 1
        $dataArray[2][] = ''; // payee's address line 2

        $dataArray[2] = implode("\t", $dataArray[2]);

        $data = implode("\t", $dataArray);

        // get the crc32 of the string in binary format and prepend it to the data
        $hashedData = strrev(hash('crc32b', $data, true)) . $data;
        $xzBinary = $this->getXzBinary();

        // we need to get raw lzma1 compressed data with parameters LC=3, LP=0, PB=2, DICT_SIZE=128KiB
        $xzProcess = proc_open("${xzBinary} '--format=raw' '--lzma1=lc=3,lp=0,pb=2,dict=128KiB' '-c' '-'", [
            0 => [
                'pipe',
                'r',
            ],
            1 => [
                'pipe',
                'w',
            ],
        ], $xzProcessPipes);
        assert(is_resource($xzProcess));

        fwrite($xzProcessPipes[0], $hashedData);
        fclose($xzProcessPipes[0]);

        $pipeOutput = stream_get_contents($xzProcessPipes[1]);
        fclose($xzProcessPipes[1]);
        proc_close($xzProcess);

        // we need to strip the EOF data and prepend 4 bytes of data, first 2 bytes define document type, the other 2
        // define the length of original string, all the magic below does that
        $hashedData = bin2hex("\x00\x00" . pack('v', strlen($hashedData)) . $pipeOutput);

        $base64Data = '';
        for ($i = 0; $i < strlen($hashedData); $i++) {
            $base64Data .= str_pad(base_convert($hashedData[$i], 16, 2), 4, '0', STR_PAD_LEFT);
        }

        $length = strlen($base64Data);

        $controlDigit = $length % 5;
        if ($controlDigit > 0) {
            $count = 5 - $controlDigit;
            $base64Data .= str_repeat('0', $count);
            $length += $count;
        }

        $length = $length / 5;

        $hashedData = str_repeat('_', $length);

        // convert the resulting binary data (5 bits at a time) according to table from specification
        for ($i = 0; $i < $length; $i++) {
            $hashedData[$i] = '0123456789ABCDEFGHIJKLMNOPQRSTUV'[bindec(substr($base64Data, $i * 5, 5))];
        }

        // and that's it, this totally-not-crazy-overkill-format-that-allows-you-to-sell-your-proprietary-solution
        // process is done
        return $hashedData;
    }

    /**
     * Return QrCode object with QrString set, for more info see Endroid QrCode
     * documentation
     *
     * @throws QrPaymentException
     *
     * @return QrCode
     */
    public function getQrImage(): QrCode
    {
        if (!class_exists("Endroid\QrCode\QrCode")) {
            throw new QrPaymentException('Error: library endroid/qr-code is not loaded.');
        }

        return new QrCode($this->getQrString());
    }

    /**
     * @param string|IbanInterface $iban
     *
     * @return static
     */
    public static function fromIBAN($iban): self
    {
        if (is_string($iban)) {
            $iban = new IbanBicPair($iban);
        } elseif (!$iban instanceof IbanInterface) {
            throw new InvalidTypeException([
                'string',
                IbanInterface::class,
            ], $iban);
        }

        return self::fromIBANs([$iban]);
    }

    /**
     * @param IbanInterface[] $ibans
     *
     * @throws QrPaymentException
     *
     * @return QrPayment
     */
    public static function fromIBANs(array $ibans): self
    {
        $instance = new static(...$ibans);

        return $instance;
    }

    public function addIban(IbanInterface $iban): self
    {
        if (!isset($this->ibans[$iban->asString()])) {
            $this->ibans[$iban->asString()] = $iban;
        }

        return $this;
    }

    public function removeIban(IbanInterface $iban): self
    {
        if (isset($this->ibans[$iban->asString()])) {
            unset($this->ibans[$iban->asString()]);
        }

        return $this;
    }

    /**
     * @return IbanInterface[]
     */
    public function getIbans(): array
    {
        return $this->ibans;
    }

    /**
     * @param IbanInterface[] $ibans
     *
     * @return QrPayment
     */
    public function setIbans(array $ibans): self
    {
        foreach ($this->ibans as $iban) {
            $this->removeIban($iban);
        }
        foreach ($ibans as $iban) {
            $this->addIban($iban);
        }

        return $this;
    }

    /**
     * @param int $variableSymbol
     *
     * @return QrPayment
     */
    public function setVariableSymbol($variableSymbol): self
    {
        $this->variableSymbol = $variableSymbol;

        return $this;
    }

    /**
     * @param int $specificSymbol
     *
     * @return QrPayment
     */
    public function setSpecificSymbol($specificSymbol): self
    {
        $this->specificSymbol = $specificSymbol;

        return $this;
    }

    /**
     * @param int $constantSymbol
     *
     * @return QrPayment
     */
    public function setConstantSymbol($constantSymbol): self
    {
        $this->constantSymbol = $constantSymbol;

        return $this;
    }

    /**
     * @param string $currency
     *
     * @return QrPayment
     */
    public function setCurrency($currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    /**
     * @param string $comment
     *
     * @return QrPayment
     */
    public function setComment($comment): self
    {
        $this->comment = $comment;

        return $this;
    }

    /**
     * @param string $internalId
     *
     * @return QrPayment
     */
    public function setInternalId($internalId): self
    {
        $this->internalId = $internalId;

        return $this;
    }

    /**
     * @param DateTimeInterface $dueDate
     *
     * @return QrPayment
     */
    public function setDueDate(DateTimeInterface $dueDate): self
    {
        $this->dueDate = $dueDate;

        return $this;
    }

    /**
     * @param float $amount
     *
     * @return QrPayment
     */
    public function setAmount($amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    /**
     * @param string $country
     *
     * @return QrPayment
     */
    public function setCountry($country): self
    {
        $this->country = $country;

        return $this;
    }

    /**
     * @param string $payeeName
     *
     * @return QrPayment
     */
    public function setPayeeName(string $payeeName): QrPayment
    {
        $this->payeeName = $payeeName;

        return $this;
    }

    /**
     * @param string $binaryPath
     *
     * @return $this
     */
    public function setXzBinary($binaryPath): self
    {
        $this->xzPath = $binaryPath;

        return $this;
    }

    /**
     * @throws QrPaymentException
     *
     * @return string
     */
    public function getXzBinary(): string
    {
        if (is_null($this->xzPath)) {
            exec('which xz', $output, $return);
            if ($return !== 0) {
                throw new QrPaymentException("'xz' binary not found in PATH, specify it using setXzBinary()");
            }
            if (!isset($output[0])) {
                throw new QrPaymentException("'xz' binary not found in PATH, specify it using setXzBinary()");
            }
            $this->xzPath = $output[0];
        }
        if (!file_exists($this->xzPath)) {
            throw new QrPaymentException("The path '{$this->xzPath}' to 'xz' binary is invalid");
        }

        return $this->xzPath;
    }

    /**
     * Checks whether the due date is set.
     * Throws exception if the date format cannot be parsed by strtotime() func
     *
     * @return DateTimeInterface
     */
    private function getDueDate(): DateTimeInterface
    {
        if ($this->dueDate === null) {
            return new DateTime();
        }

        return $this->dueDate;
    }

    /**
     * @return IbanBicPair[]
     */
    private function getNormalizedIbans(): array
    {
        $result = [];
        foreach ($this->ibans as $iban) {
            if (!$iban instanceof IbanBicPair) {
                $result[] = new IbanBicPair($iban);
            } else {
                $result[] = $iban;
            }
        }

        return $result;
    }
}
