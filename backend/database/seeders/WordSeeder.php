<?php

namespace Database\Seeders;

use App\Models\Word;
use Illuminate\Database\Seeder;

class WordSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->words() as $w) {
            Word::updateOrCreate(
                ['word' => $w[0]],
                [
                    'part_of_speech' => $w[1],
                    'definition_zh' => $w[2],
                    'example' => $w[3],
                    'category' => $w[4],
                    'level' => $w[5],
                    'source' => 'seed',
                ]
            );
        }
    }

    /**
     * [word, part_of_speech, definition_zh, example, category, level]
     */
    private function words(): array
    {
        return [
            // --- Business / Management ---
            ['acquire', 'verb', '取得、收購', 'The company plans to acquire a smaller competitor.', 'business', 3],
            ['merger', 'noun', '合併', 'The merger created the largest firm in the industry.', 'business', 4],
            ['negotiate', 'verb', '談判、協商', 'They negotiated a better contract with the supplier.', 'business', 3],
            ['strategy', 'noun', '策略', 'Our marketing strategy focuses on younger customers.', 'business', 3],
            ['expand', 'verb', '擴展', 'The firm decided to expand into overseas markets.', 'business', 3],
            ['revenue', 'noun', '收入、營收', 'Annual revenue increased by ten percent.', 'finance', 3],
            ['profit', 'noun', '利潤', 'The company reported record profits this quarter.', 'finance', 2],
            ['budget', 'noun', '預算', 'We must stay within the marketing budget.', 'finance', 2],
            ['invest', 'verb', '投資', 'They decided to invest in new equipment.', 'finance', 3],
            ['stakeholder', 'noun', '利害關係人', 'All stakeholders were invited to the meeting.', 'business', 4],
            ['competitor', 'noun', '競爭對手', 'Our main competitor lowered their prices.', 'business', 3],
            ['launch', 'verb', '推出、發行', 'The company will launch a new product next month.', 'marketing', 3],
            ['venture', 'noun', '企業、冒險事業', 'The joint venture proved highly profitable.', 'business', 4],
            ['decline', 'verb', '下降、婉拒', 'Sales declined sharply during the recession.', 'finance', 3],
            ['forecast', 'noun', '預測', 'The sales forecast looks promising for next year.', 'business', 4],

            // --- Office / Workplace ---
            ['colleague', 'noun', '同事', 'I asked a colleague to review my report.', 'office', 2],
            ['supervisor', 'noun', '主管、上司', 'Please submit the form to your supervisor.', 'office', 3],
            ['deadline', 'noun', '截止期限', 'We must meet the deadline by Friday.', 'office', 2],
            ['schedule', 'noun', '時間表、行程', 'The meeting is not on my schedule.', 'office', 2],
            ['agenda', 'noun', '議程', 'The first item on the agenda is the budget.', 'office', 3],
            ['memo', 'noun', '備忘錄', 'She sent a memo about the new policy.', 'office', 2],
            ['minutes', 'noun', '會議記錄', 'Could you take the minutes of the meeting?', 'office', 3],
            ['overtime', 'noun', '加班', 'Employees are paid extra for overtime.', 'office', 2],
            ['shift', 'noun', '輪班', 'He works the night shift at the factory.', 'office', 2],
            ['promotion', 'noun', '升遷、促銷', 'She received a promotion to senior manager.', 'office', 3],
            ['resign', 'verb', '辭職', 'He decided to resign from his position.', 'office', 3],
            ['recruit', 'verb', '招募', 'The company is recruiting new staff.', 'hr', 3],
            ['applicant', 'noun', '應徵者', 'Each applicant must submit a resume.', 'hr', 3],
            ['candidate', 'noun', '候選人', 'We interviewed five candidates for the role.', 'hr', 3],
            ['résumé', 'noun', '履歷', 'Please attach your résumé to the application.', 'hr', 3],
            ['interview', 'noun', '面試', 'The interview lasted about an hour.', 'hr', 2],
            ['qualified', 'adjective', '有資格的', 'She is highly qualified for the position.', 'hr', 3],
            ['benefit', 'noun', '福利、好處', 'The job offers excellent health benefits.', 'hr', 2],
            ['salary', 'noun', '薪水', 'The salary is negotiable based on experience.', 'hr', 2],
            ['raise', 'noun', '加薪', 'She asked her boss for a raise.', 'hr', 2],
            ['vacancy', 'noun', '職缺、空位', 'There is a vacancy in the sales department.', 'hr', 3],

            // --- Meetings / Communication ---
            ['propose', 'verb', '提議', 'He proposed a new approach to the project.', 'business', 3],
            ['confirm', 'verb', '確認', 'Please confirm your attendance by email.', 'office', 2],
            ['postpone', 'verb', '延期', 'The meeting was postponed until next week.', 'office', 3],
            ['attend', 'verb', '出席', 'All managers must attend the conference.', 'office', 2],
            ['conference', 'noun', '會議、大會', 'The annual conference is held in Tokyo.', 'office', 2],
            ['presentation', 'noun', '簡報', 'Her presentation impressed the clients.', 'office', 2],
            ['summarize', 'verb', '總結', 'Could you summarize the main points?', 'office', 3],
            ['clarify', 'verb', '澄清', 'Let me clarify what I meant earlier.', 'office', 3],
            ['feedback', 'noun', '回饋意見', 'The manager gave helpful feedback.', 'office', 2],
            ['inquiry', 'noun', '詢問', 'We received an inquiry about our products.', 'business', 3],

            // --- Contracts / Legal ---
            ['contract', 'noun', '合約', 'Both parties signed the contract.', 'contracts', 2],
            ['agreement', 'noun', '協議', 'They reached an agreement after long talks.', 'contracts', 2],
            ['clause', 'noun', '條款', 'The contract contains a confidentiality clause.', 'contracts', 4],
            ['terminate', 'verb', '終止', 'Either party may terminate the agreement.', 'contracts', 4],
            ['comply', 'verb', '遵守', 'All firms must comply with the regulations.', 'contracts', 3],
            ['liable', 'adjective', '負有責任的', 'The company is liable for any damages.', 'contracts', 4],
            ['obligation', 'noun', '義務', 'He fulfilled his contractual obligations.', 'contracts', 4],
            ['warranty', 'noun', '保固', 'The product comes with a two-year warranty.', 'contracts', 3],
            ['amend', 'verb', '修改', 'The board voted to amend the policy.', 'contracts', 4],
            ['breach', 'noun', '違反', 'Late delivery is a breach of contract.', 'contracts', 4],

            // --- Finance / Accounting ---
            ['invoice', 'noun', '發票、帳單', 'Please pay the invoice within 30 days.', 'finance', 3],
            ['expense', 'noun', '費用、開支', 'Travel expenses will be reimbursed.', 'finance', 2],
            ['reimburse', 'verb', '償還、報銷', 'The company will reimburse your costs.', 'finance', 4],
            ['deposit', 'noun', '訂金、存款', 'A deposit is required to reserve the room.', 'finance', 2],
            ['refund', 'noun', '退款', 'You can request a refund within a week.', 'finance', 2],
            ['transaction', 'noun', '交易', 'Each transaction is recorded automatically.', 'finance', 3],
            ['account', 'noun', '帳戶、客戶', 'Funds were transferred to her account.', 'finance', 2],
            ['audit', 'noun', '審計、查帳', 'The annual audit revealed no problems.', 'finance', 4],
            ['fiscal', 'adjective', '財政的', 'The fiscal year ends in March.', 'finance', 4],
            ['estimate', 'noun', '估價、估計', 'We sent the client a cost estimate.', 'finance', 3],

            // --- Marketing / Sales ---
            ['advertise', 'verb', '廣告宣傳', 'They advertise their products online.', 'marketing', 2],
            ['campaign', 'noun', '活動', 'The ad campaign boosted sales.', 'marketing', 3],
            ['brand', 'noun', '品牌', 'The company built a strong brand image.', 'marketing', 2],
            ['consumer', 'noun', '消費者', 'Consumers prefer eco-friendly products.', 'marketing', 3],
            ['discount', 'noun', '折扣', 'Members receive a ten percent discount.', 'marketing', 2],
            ['promote', 'verb', '推廣、促銷', 'They promoted the sale on social media.', 'marketing', 3],
            ['retail', 'noun', '零售', 'The retail price is higher than wholesale.', 'marketing', 3],
            ['wholesale', 'noun', '批發', 'They buy goods at wholesale prices.', 'marketing', 4],
            ['demand', 'noun', '需求', 'There is high demand for the new phone.', 'marketing', 2],
            ['target', 'verb', '以…為目標', 'The ad targets young professionals.', 'marketing', 3],

            // --- Logistics / Shipping ---
            ['shipment', 'noun', '貨運、出貨', 'The shipment will arrive on Monday.', 'logistics', 3],
            ['deliver', 'verb', '運送', 'We deliver orders within two days.', 'logistics', 2],
            ['inventory', 'noun', '庫存', 'We need to check the inventory levels.', 'logistics', 3],
            ['warehouse', 'noun', '倉庫', 'The goods are stored in the warehouse.', 'logistics', 3],
            ['supplier', 'noun', '供應商', 'We found a reliable supplier overseas.', 'logistics', 3],
            ['freight', 'noun', '貨運、運費', 'Freight costs have risen this year.', 'logistics', 4],
            ['cargo', 'noun', '貨物', 'The ship carried a large cargo.', 'logistics', 3],
            ['distribute', 'verb', '分發、配送', 'They distribute goods nationwide.', 'logistics', 3],
            ['logistics', 'noun', '物流', 'Efficient logistics reduce delivery time.', 'logistics', 4],
            ['ship', 'verb', '運送', 'We will ship your order tomorrow.', 'logistics', 2],

            // --- Travel / Hospitality ---
            ['itinerary', 'noun', '行程表', 'The travel agent sent the itinerary.', 'travel', 4],
            ['reservation', 'noun', '預訂', 'I made a reservation for two nights.', 'travel', 2],
            ['accommodation', 'noun', '住宿', 'The hotel offers comfortable accommodation.', 'travel', 3],
            ['departure', 'noun', '出發', 'The departure time is 9 a.m.', 'travel', 2],
            ['boarding', 'noun', '登機', 'Boarding will begin in ten minutes.', 'travel', 3],
            ['luggage', 'noun', '行李', 'Please keep your luggage with you.', 'travel', 2],
            ['delay', 'noun', '延誤', 'The flight delay lasted two hours.', 'travel', 2],
            ['fare', 'noun', '車費、票價', 'The bus fare increased last month.', 'travel', 3],
            ['destination', 'noun', '目的地', 'Paris is a popular travel destination.', 'travel', 3],
            ['confirm', 'verb', '確認', 'Please confirm your hotel reservation.', 'travel', 2],

            // --- Technology / IT ---
            ['upgrade', 'verb', '升級', 'We need to upgrade the software.', 'technology', 3],
            ['install', 'verb', '安裝', 'Please install the latest version.', 'technology', 2],
            ['device', 'noun', '裝置', 'The device connects to Wi-Fi easily.', 'technology', 2],
            ['access', 'verb', '存取、進入', 'You can access the files remotely.', 'technology', 3],
            ['data', 'noun', '資料', 'All customer data is kept secure.', 'technology', 2],
            ['network', 'noun', '網路', 'The office network is very fast.', 'technology', 2],
            ['maintenance', 'noun', '維護', 'The system is down for maintenance.', 'technology', 3],
            ['feature', 'noun', '功能、特色', 'The app has many useful features.', 'technology', 2],
            ['compatible', 'adjective', '相容的', 'The software is compatible with all devices.', 'technology', 4],
            ['glitch', 'noun', '小故障', 'A software glitch delayed the launch.', 'technology', 4],

            // --- General high-frequency TOEIC adjectives / verbs ---
            ['efficient', 'adjective', '有效率的', 'The new system is more efficient.', 'general', 3],
            ['reliable', 'adjective', '可靠的', 'He is a reliable employee.', 'general', 3],
            ['available', 'adjective', '可用的、有空的', 'The manager is available this afternoon.', 'general', 2],
            ['significant', 'adjective', '重要的、顯著的', 'There was a significant increase in sales.', 'general', 4],
            ['essential', 'adjective', '必要的', 'Teamwork is essential for success.', 'general', 3],
            ['temporary', 'adjective', '臨時的', 'They hired temporary workers for summer.', 'general', 3],
            ['accurate', 'adjective', '準確的', 'Please make sure the figures are accurate.', 'general', 3],
            ['mandatory', 'adjective', '強制的', 'Attendance at the training is mandatory.', 'general', 4],
            ['flexible', 'adjective', '有彈性的', 'We offer flexible working hours.', 'general', 3],
            ['extensive', 'adjective', '廣泛的、大量的', 'She has extensive experience in finance.', 'general', 4],
            ['enhance', 'verb', '提升、增強', 'The update will enhance performance.', 'general', 4],
            ['implement', 'verb', '實施', 'The company will implement new rules.', 'general', 4],
            ['ensure', 'verb', '確保', 'Please ensure the door is locked.', 'general', 3],
            ['require', 'verb', '需要、要求', 'The job requires strong communication skills.', 'general', 2],
            ['determine', 'verb', '決定、確定', 'The test will determine your level.', 'general', 3],
            ['obtain', 'verb', '獲得', 'You must obtain approval first.', 'general', 3],
            ['submit', 'verb', '提交', 'Submit your application by Friday.', 'general', 2],
            ['approve', 'verb', '批准', 'The board approved the budget.', 'general', 3],
            ['assign', 'verb', '指派', 'The task was assigned to the new team.', 'general', 3],
            ['evaluate', 'verb', '評估', 'Managers evaluate staff every year.', 'general', 3],
            ['anticipate', 'verb', '預期', 'We anticipate strong sales this season.', 'general', 4],
            ['accommodate', 'verb', '容納、配合', 'The hall can accommodate 500 people.', 'general', 4],
            ['recommend', 'verb', '推薦、建議', 'The board recommends approving the plan.', 'general', 3],
            ['postpone', 'verb', '延期', 'They postponed the launch until June.', 'general', 3],
            ['establish', 'verb', '建立', 'The firm was established in 1990.', 'general', 3],
            ['maintain', 'verb', '維持', 'It is important to maintain quality.', 'general', 3],
            ['reduce', 'verb', '減少', 'We aim to reduce costs this year.', 'general', 2],
            ['increase', 'verb', '增加', 'Sales increased over the holidays.', 'general', 2],
            ['purchase', 'verb', '購買', 'Customers can purchase tickets online.', 'general', 2],
            ['replace', 'verb', '取代、更換', 'We need to replace the old printer.', 'general', 2],
            ['notify', 'verb', '通知', 'Please notify us of any changes.', 'general', 3],
            ['attach', 'verb', '附上', 'I have attached the report to this email.', 'general', 2],
            ['proceed', 'verb', '繼續進行', 'We may now proceed with the plan.', 'general', 3],
            ['conduct', 'verb', '進行、執行', 'They conducted a survey last month.', 'general', 3],
            ['distribute', 'verb', '分發', 'Please distribute the handouts.', 'general', 3],
        ];
    }
}
