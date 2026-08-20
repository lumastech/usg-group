<?php

namespace Database\Seeders;

use App\Domain\Cycles\CycleMonthPlanner;
use App\Enums\CycleStatus;
use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Enums\NextOfKinRelationship;
use App\Enums\WeekendTradingPolicy;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\NextOfKin;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Seeds the 2025–2026 cycle and its 30 members.
 *
 * Member details come from the signed Commitment Sheet of the group constitution.
 * Members hold no email address in that document, so portal logins are generated
 * from their names; the group must reset these before going live.
 */
class UnityCycleSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'password';

    public const EMAIL_DOMAIN = 'unitysavings.test';

    public function run(): void
    {
        $cycle = Cycle::updateOrCreate(
            ['name' => '2025–2026'],
            [
                'starts_on' => Carbon::parse('2025-12-01'),
                'ends_on' => Carbon::parse('2026-11-30'),
                'registration_closes_after_month' => 3,
                'loan_lockdown_starts_month' => 10,
                'final_repayment_date' => Carbon::parse('2026-11-07'),
                'weekend_trading_policy' => WeekendTradingPolicy::NextMonday,
                'status' => CycleStatus::Active,
            ],
        );

        app(CycleMonthPlanner::class)->plan($cycle);

        foreach ($this->members() as $index => $attributes) {
            $this->seedMember($cycle, $index + 1, $attributes);
        }

        $this->assignCommittee($cycle);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function seedMember(Cycle $cycle, int $number, array $attributes): void
    {
        $user = User::updateOrCreate(
            ['email' => $this->emailFor($attributes['full_name'])],
            [
                'name' => $attributes['full_name'],
                'password' => self::DEMO_PASSWORD,
                'email_verified_at' => Carbon::now(),
            ],
        );

        $nextOfKin = [
            'name' => $attributes['next_of_kin_name'] ?? null,
            'phone' => $attributes['next_of_kin_phone'] ?? null,
            'relationship' => $attributes['next_of_kin_relationship'] ?? null,
        ];

        unset(
            $attributes['next_of_kin_name'],
            $attributes['next_of_kin_phone'],
            $attributes['next_of_kin_relationship'],
        );

        $member = Member::updateOrCreate(
            ['cycle_id' => $cycle->id, 'member_number' => $number],
            $attributes + [
                'user_id' => $user->id,
                'is_diaspora' => false,
                'status' => MemberStatus::Active,
                'joined_on' => $cycle->starts_on,
                'joining_month_sequence' => 1,
                'joining_fee_ngwee' => $cycle->joining_fee_ngwee,
                'joining_fee_paid' => true,
            ],
        );

        $this->seedNextOfKin($member, $nextOfKin);

        $user->syncRoles([MemberRole::Member->value]);
    }

    /**
     * The sheet records one nominee per member, and two members named none at all.
     *
     * @param  array{name: string|null, phone: string|null, relationship: string|null}  $nextOfKin
     */
    protected function seedNextOfKin(Member $member, array $nextOfKin): void
    {
        if ($nextOfKin['name'] === null) {
            return;
        }

        NextOfKin::updateOrCreate(
            ['member_id' => $member->id, 'name' => $nextOfKin['name']],
            [
                'phone' => $nextOfKin['phone'],
                'relationship' => NextOfKinRelationship::fromLabel($nextOfKin['relationship']),
                'relationship_label' => $nextOfKin['relationship'],
            ],
        );
    }

    /** Committee as recorded in section 4 of the constitution. */
    protected function assignCommittee(Cycle $cycle): void
    {
        $offices = [
            'Maingaila Makombe' => MemberRole::Chairperson,
            'Gloria Kangwa' => MemberRole::ViceChairperson,
            'Mirriam Lungu' => MemberRole::Treasurer,
            'Thelma P Chanda' => MemberRole::ViceTreasurer,
        ];

        foreach ($offices as $name => $role) {
            $member = $cycle->members()->where('full_name', $name)->first();

            $member?->user?->assignRole($role->value);
        }
    }

    protected function emailFor(string $name): string
    {
        return Str::of($name)->lower()->replace(' ', '.')->slug('.')->append('@'.self::EMAIL_DOMAIN)->toString();
    }

    /**
     * The 30 members in workbook row order, with details from the Commitment Sheet.
     *
     * Two entries carry no NRC or next-of-kin because the signed sheet does not record
     * them, and no member's own phone number appears in that document.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function members(): array
    {
        return [
            ['full_name' => 'Bernadette Kashweka', 'nrc_number' => '188612/10/1', 'physical_address' => 'Meanwood Ibex Little Mead Villa', 'next_of_kin_name' => 'Pamela Kashweka', 'next_of_kin_phone' => '0977496538', 'next_of_kin_relationship' => 'Sister'],
            ['full_name' => 'Bertha Chileshe', 'nrc_number' => '155110/10/1', 'physical_address' => 'Lilayi, Chandamali Road', 'next_of_kin_name' => 'Nobya Situmbeko', 'next_of_kin_phone' => '0979458327', 'next_of_kin_relationship' => 'Spouse'],
            ['full_name' => 'Bertha Mabando', 'nrc_number' => '300929/64/1', 'physical_address' => '46 Masa Street, Libala Stage 4', 'next_of_kin_name' => 'Agness Chipupu', 'next_of_kin_phone' => '0961897719', 'next_of_kin_relationship' => 'Mother'],
            ['full_name' => 'Eustus Mulenga', 'nrc_number' => '908644/11/1', 'physical_address' => 'Meanwood Ndeke 3818, Airport', 'next_of_kin_name' => null, 'next_of_kin_phone' => '0977795264', 'next_of_kin_relationship' => 'Spouse'],
            ['full_name' => 'Gift Kunda', 'nrc_number' => '172852/18/1', 'physical_address' => 'Kamenza East, Chililabombwe', 'next_of_kin_name' => 'Veronica C Kunda', 'next_of_kin_phone' => '0977103475', 'next_of_kin_relationship' => 'Mother'],
            ['full_name' => 'Gloria Kangwa', 'nrc_number' => '349239/43/1', 'physical_address' => 'Meanwood Mutumbi Phase 2, A2247/32a', 'next_of_kin_name' => 'Matildah Kangwa', 'next_of_kin_phone' => '0978451142', 'next_of_kin_relationship' => 'Sister'],
            ['full_name' => 'Ireen Seta', 'nrc_number' => null, 'physical_address' => 'Mwalubemba Farm Blocks, Chongwe', 'next_of_kin_name' => 'Carol Seta', 'next_of_kin_phone' => '0979355614', 'next_of_kin_relationship' => 'Sister'],
            ['full_name' => 'Jessica Mudenda', 'nrc_number' => '567148/10/1', 'physical_address' => 'Farms 1956/60 Mungwi Road, Lusaka West', 'next_of_kin_name' => 'Jane Chintu', 'next_of_kin_phone' => '0774943456', 'next_of_kin_relationship' => 'Aunt'],
            ['full_name' => 'Lukundo Sindano', 'nrc_number' => '948989/11/1', 'physical_address' => 'Ngwerere', 'next_of_kin_name' => 'Elias Kangwa', 'next_of_kin_phone' => '0977537582', 'next_of_kin_relationship' => 'Spouse'],
            ['full_name' => 'Maingaila Makombe', 'nrc_number' => '769090/11/1', 'physical_address' => 'Farm 14649, Chifwema', 'next_of_kin_name' => 'Mutija L Mbewe', 'next_of_kin_phone' => '0973092956', 'next_of_kin_relationship' => 'Sister'],
            ['full_name' => 'Maureen Phiri', 'nrc_number' => '143650/10/1', 'physical_address' => '11 Chelstone, off Palm Drive Road', 'next_of_kin_name' => 'Precious Phiri', 'next_of_kin_phone' => '0974132962', 'next_of_kin_relationship' => 'Sister'],
            ['full_name' => 'Merindah Kabwe', 'nrc_number' => '388775/67/1', 'physical_address' => 'Plot RR9 New ChaChaCha, Kitwe', 'next_of_kin_name' => 'Harriet Ngobela', 'next_of_kin_phone' => '0977711091', 'next_of_kin_relationship' => 'Mother'],
            ['full_name' => 'Mirriam Lungu', 'nrc_number' => '757489/11/1', 'physical_address' => 'P20/42 C5 Estates, Kafue Chipata Road', 'next_of_kin_name' => 'Sarah Lungu', 'next_of_kin_phone' => '0968461754', 'next_of_kin_relationship' => 'Mother'],
            ['full_name' => 'Mirriam Phiri', 'nrc_number' => null, 'physical_address' => null, 'next_of_kin_name' => null, 'next_of_kin_phone' => null, 'next_of_kin_relationship' => null],
            ['full_name' => 'Mirriam Mumba', 'nrc_number' => '153972/10/1', 'physical_address' => 'Lusaka West, off Kasupe Road', 'next_of_kin_name' => 'Ndonji Sasenge', 'next_of_kin_phone' => '0979332780', 'next_of_kin_relationship' => 'Spouse'],
            ['full_name' => 'Munshya Mbasela', 'nrc_number' => '361685/65/1', 'physical_address' => 'Zesco Flats, Kabulonga', 'next_of_kin_name' => 'Gilbert Mokola', 'next_of_kin_phone' => '0977998347', 'next_of_kin_relationship' => 'Spouse'],
            ['full_name' => 'Mutija M Lifunana', 'nrc_number' => '351225/65/1', 'physical_address' => 'Plot L39, Lilayi', 'next_of_kin_name' => 'Chafwa Mbewe', 'next_of_kin_phone' => '0977652025', 'next_of_kin_relationship' => 'Spouse'],
            ['full_name' => 'Mwansa Chitomfwa', 'nrc_number' => '294109/16/1', 'physical_address' => 'Chalala Apex, Augustin Lungu Road', 'next_of_kin_name' => 'Shula Kasongamulilo', 'next_of_kin_phone' => '0977880227', 'next_of_kin_relationship' => 'Spouse'],
            ['full_name' => 'Mwansa Kaleya', 'nrc_number' => '435889/67/1', 'physical_address' => 'Plot 24807 Waterworks Road, Libala South', 'next_of_kin_name' => 'Chomba Kaleya', 'next_of_kin_phone' => '0973253101', 'next_of_kin_relationship' => 'Brother'],
            ['full_name' => 'Mwiche Simwanza', 'nrc_number' => '279707/10/1', 'physical_address' => 'Chalala Apex', 'next_of_kin_name' => 'Loveness Simwanza', 'next_of_kin_phone' => '0977832045', 'next_of_kin_relationship' => 'Mother'],
            ['full_name' => 'Nchimunya Ngandu', 'nrc_number' => '212552/65/1', 'physical_address' => 'Muzi Wesu Farms, Kalulushi', 'next_of_kin_name' => 'Meangrade Munsanje', 'next_of_kin_phone' => '0977917445', 'next_of_kin_relationship' => 'Mother'],
            ['full_name' => 'Patricia Chali', 'nrc_number' => '415956/65/1', 'physical_address' => 'PHI', 'next_of_kin_name' => 'Laurie Chibambo', 'next_of_kin_phone' => '0961099668', 'next_of_kin_relationship' => 'Daughter'],
            ['full_name' => 'Pauline Mumba', 'nrc_number' => '411507/74/1', 'physical_address' => 'Foxdale Police Post', 'next_of_kin_name' => 'Petronella Mumba', 'next_of_kin_phone' => '0977451590', 'next_of_kin_relationship' => 'Sister'],
            ['full_name' => 'Precious Kabange', 'nrc_number' => '3064429/66/1', 'physical_address' => 'House No. M38 Ngwerere Road, Chelstone', 'next_of_kin_name' => 'Godfrey Kabange', 'next_of_kin_phone' => '0977533420', 'next_of_kin_relationship' => 'Brother'],
            ['full_name' => 'Priscilla Kasonde', 'nrc_number' => '244246/10/1', 'physical_address' => 'Chalala, Lottie Mwale Street', 'next_of_kin_name' => 'Bornface Chipandwe', 'next_of_kin_phone' => '0979662209', 'next_of_kin_relationship' => 'Spouse'],
            ['full_name' => 'Safi Kabengele', 'nrc_number' => '141921/65/1', 'physical_address' => 'Chalala, Lusaka', 'next_of_kin_name' => 'Cynthia Chikonde', 'next_of_kin_phone' => '0974156692', 'next_of_kin_relationship' => 'Daughter'],
            ['full_name' => 'Sarah Nyendwa', 'nrc_number' => '539353/11/1', 'physical_address' => 'H/No 5393 SOS', 'next_of_kin_name' => 'Mwila Phiri', 'next_of_kin_phone' => '0970994333', 'next_of_kin_relationship' => 'Daughter'],
            ['full_name' => 'Siitu Simutanyi', 'nrc_number' => '886832/11/1', 'physical_address' => 'Meanwood Kwamwena Phase 1', 'next_of_kin_name' => 'Rose Kasapo', 'next_of_kin_phone' => '0977616533', 'next_of_kin_relationship' => 'Sister'],
            ['full_name' => 'Stella Muzuwa', 'nrc_number' => '213718/18/1', 'physical_address' => 'Kabwata Site & Service, Plot 429', 'next_of_kin_name' => 'Namakoka Muzuwa', 'next_of_kin_phone' => '0977457665', 'next_of_kin_relationship' => 'Sister'],
            ['full_name' => 'Thelma P Chanda', 'nrc_number' => '304310/66/1', 'physical_address' => '15 Mbewe Flats, Burma', 'next_of_kin_name' => 'Alfred Chanda', 'next_of_kin_phone' => '0966149097', 'next_of_kin_relationship' => 'Spouse'],
        ];
    }
}
